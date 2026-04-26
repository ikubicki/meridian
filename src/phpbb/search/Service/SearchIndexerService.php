<?php

/**
 *
 * This file is part of the phpBB4 "Meridian" package.
 *
 * @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

declare(strict_types=1);

namespace phpbb\search\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use phpbb\cache\TagAwareCacheInterface;
use phpbb\search\Contract\SearchIndexerInterface;
use phpbb\search\Tokenizer\NativeTokenizer;
use Psr\Log\LoggerInterface;

final class SearchIndexerService implements SearchIndexerInterface
{
	public function __construct(
		private readonly Connection $connection,
		private readonly NativeTokenizer $tokenizer,
		private readonly LoggerInterface $logger,
		private readonly TagAwareCacheInterface $cache,
	) {
	}

	public function indexPost(int $postId, string $text, string $subject, int $forumId): void
	{
		try {
			$this->deindexPost($postId);

			$textTokens    = $this->tokenizer->tokenize($text);
			$subjectTokens = $this->tokenizer->tokenize($subject);

			$bodyWords  = array_unique(array_merge($textTokens['must'], $textTokens['should']));
			$titleWords = array_unique(array_merge($subjectTokens['must'], $subjectTokens['should']));

			foreach ($bodyWords as $word) {
				$wordId = $this->upsertWord($word);
				$this->insertWordmatch($postId, $wordId, 0);
			}

			foreach ($titleWords as $word) {
				$wordId = $this->upsertWord($word);
				$this->insertWordmatch($postId, $wordId, 1);
			}
		} catch (\Doctrine\DBAL\Exception $e) {
			$this->logger->warning('SearchIndexerService: failed to index post {postId}: {message}', [
				'postId'  => $postId,
				'message' => $e->getMessage(),
			]);
		}
	}

	public function deindexPost(int $postId): void
	{
		try {
			$wordIds = $this->connection->createQueryBuilder()
				->select('word_id')
				->from('phpbb_search_wordmatch')
				->where('post_id = :postId')
				->setParameter('postId', $postId)
				->executeQuery()
				->fetchFirstColumn();

			$this->connection->createQueryBuilder()
				->delete('phpbb_search_wordmatch')
				->where('post_id = :postId')
				->setParameter('postId', $postId)
				->executeStatement();

			foreach ($wordIds as $wordId) {
				$this->connection->createQueryBuilder()
					->update('phpbb_search_wordlist')
					->set('word_count', 'word_count - 1')
					->where('word_id = :wordId')
					->setParameter('wordId', (int) $wordId)
					->executeStatement();
			}

			$this->connection->createQueryBuilder()
				->delete('phpbb_search_wordlist')
				->where('word_count <= 0')
				->executeStatement();

			try {
				$this->cache->invalidateTags(['search']);
			} catch (\Throwable $e) {
				$this->logger->warning('SearchIndexerService: cache invalidation failed after deindex: {message}', [
					'message' => $e->getMessage(),
				]);
			}
		} catch (\Doctrine\DBAL\Exception $e) {
			$this->logger->warning('SearchIndexerService: failed to deindex post {postId}: {message}', [
				'postId'  => $postId,
				'message' => $e->getMessage(),
			]);
		}
	}

	public function reindexAll(): void
	{
		try {
			$this->logger->info('SearchIndexerService: starting full reindex');

			$this->connection->createQueryBuilder()
				->delete('phpbb_search_wordmatch')
				->executeStatement();

			$this->connection->createQueryBuilder()
				->delete('phpbb_search_wordlist')
				->executeStatement();

			$posts = $this->connection->createQueryBuilder()
				->select('post_id', 'post_text', 'post_subject', 'forum_id')
				->from('phpbb_posts')
				->where('post_visibility = 1')
				->executeQuery()
				->fetchAllAssociative();

			$count = 0;
			foreach ($posts as $post) {
				$this->indexPost(
					(int) $post['post_id'],
					(string) $post['post_text'],
					(string) $post['post_subject'],
					(int) $post['forum_id'],
				);
				$count++;
			}

			$this->logger->info('SearchIndexerService: reindex complete, indexed {count} posts', [
				'count' => $count,
			]);

			try {
				$this->cache->invalidateTags(['search']);
			} catch (\Throwable $e) {
				$this->logger->warning('SearchIndexerService: cache invalidation failed after reindex: {message}', [
					'message' => $e->getMessage(),
				]);
			}
		} catch (\Doctrine\DBAL\Exception $e) {
			$this->logger->warning('SearchIndexerService: failed to reindex all: {message}', [
				'message' => $e->getMessage(),
			]);
		}
	}

	private function upsertWord(string $word): int
	{
		$wordId = $this->connection->createQueryBuilder()
			->select('word_id')
			->from('phpbb_search_wordlist')
			->where('word_text = :word')
			->setParameter('word', $word)
			->executeQuery()
			->fetchOne();

		if ($wordId === false) {
			$this->connection->createQueryBuilder()
				->insert('phpbb_search_wordlist')
				->values(['word_text' => ':word', 'word_count' => '1'])
				->setParameter('word', $word)
				->executeStatement();

			return (int) $this->connection->lastInsertId();
		}

		$this->connection->createQueryBuilder()
			->update('phpbb_search_wordlist')
			->set('word_count', 'word_count + 1')
			->where('word_id = :wordId')
			->setParameter('wordId', (int) $wordId)
			->executeStatement();

		return (int) $wordId;
	}

	private function insertWordmatch(int $postId, int $wordId, int $titleMatch): void
	{
		// INSERT OR IGNORE — raw SQL required; QueryBuilder cannot express INSERT OR IGNORE / INSERT IGNORE portably
		try {
			$this->connection->createQueryBuilder()
				->insert('phpbb_search_wordmatch')
				->values([
					'post_id'     => ':postId',
					'word_id'     => ':wordId',
					'title_match' => ':titleMatch',
				])
				->setParameter('postId', $postId)
				->setParameter('wordId', $wordId)
				->setParameter('titleMatch', $titleMatch)
				->executeStatement();
		} catch (UniqueConstraintViolationException) {
			// Duplicate (post_id, word_id, title_match) — already indexed, ignore
		}
	}
}
