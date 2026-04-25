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

namespace phpbb\hierarchy\Repository;

use phpbb\db\Exception\RepositoryException;
use phpbb\hierarchy\Contract\ForumRepositoryInterface;
use phpbb\hierarchy\DTO\CreateForumRequest;
use phpbb\hierarchy\DTO\UpdateForumRequest;
use phpbb\hierarchy\Entity\Forum;
use phpbb\hierarchy\Entity\ForumLastPost;
use phpbb\hierarchy\Entity\ForumPruneSettings;
use phpbb\hierarchy\Entity\ForumStats;
use phpbb\hierarchy\Entity\ForumStatus;
use phpbb\hierarchy\Entity\ForumType;

class DbalForumRepository implements ForumRepositoryInterface
{
	private const TABLE = 'phpbb_forums';

	public function __construct(
		private readonly \Doctrine\DBAL\Connection $connection,
	) {
	}

	public function findById(int $id): ?Forum
	{
		try {
			$qb  = $this->connection->createQueryBuilder();
			$row = $qb->select('*')
				->from(self::TABLE)
				->where($qb->expr()->eq('forum_id', ':id'))
				->setParameter('id', $id)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find forum by ID', previous: $e);
		}
	}

	public function findAll(): array
	{
		try {
			$rows = $this->connection->createQueryBuilder()
				->select('*')
				->from(self::TABLE)
				->orderBy('left_id', 'ASC')
				->executeQuery()
				->fetchAllAssociative();

			$result = [];
			foreach ($rows as $row) {
				$entity = $this->hydrate($row);
				$result[$entity->id] = $entity;
			}

			return $result;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find all forums', previous: $e);
		}
	}

	public function findChildren(int $parentId): array
	{
		try {
			$qb   = $this->connection->createQueryBuilder();
			$rows = $qb->select('*')
				->from(self::TABLE)
				->where($qb->expr()->eq('parent_id', ':parentId'))
				->setParameter('parentId', $parentId)
				->orderBy('left_id', 'ASC')
				->executeQuery()
				->fetchAllAssociative();

			$result = [];
			foreach ($rows as $row) {
				$entity = $this->hydrate($row);
				$result[$entity->id] = $entity;
			}

			return $result;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find forum children', previous: $e);
		}
	}

	public function insertRaw(CreateForumRequest $request): int
	{
		try {
			$this->connection->createQueryBuilder()
				->insert(self::TABLE)
				->values([
					'forum_name'            => ':forumName',
					'forum_type'            => ':forumType',
					'forum_desc'            => ':forumDesc',
					'forum_link'            => ':forumLink',
					'forum_status'          => ':forumStatus',
					'parent_id'             => ':parentId',
					'display_on_index'      => ':displayOnIndex',
					'display_subforum_list' => ':displaySubforumList',
					'enable_indexing'       => ':enableIndexing',
					'enable_icons'          => ':enableIcons',
					'forum_style'           => ':forumStyle',
					'forum_image'           => ':forumImage',
					'forum_rules'           => ':forumRules',
					'forum_rules_link'      => ':forumRulesLink',
					'forum_password'        => ':forumPassword',
					'forum_topics_per_page' => ':topicsPerPage',
					'forum_flags'           => ':forumFlags',
					'forum_parents'         => "'[]'",
					'left_id'               => '0',
					'right_id'              => '0',
					'forum_posts_approved'   => '0',
					'forum_posts_unapproved' => '0',
					'forum_posts_softdeleted' => '0',
					'forum_topics_approved'   => '0',
					'forum_topics_unapproved' => '0',
					'forum_topics_softdeleted' => '0',
					'forum_last_post_id'      => '0',
					'forum_last_poster_id'    => '0',
					'forum_last_post_subject' => "''",
					'forum_last_post_time'    => '0',
					'forum_last_poster_name'  => "''",
					'forum_last_poster_colour' => "''",
					'prune_next'   => '0',
					'prune_days'   => '0',
					'prune_viewed' => '0',
					'prune_freq'   => '0',
					'enable_prune' => '0',
				])
				->setParameter('forumName', $request->name)
				->setParameter('forumType', $request->type->value)
				->setParameter('forumDesc', $request->description)
				->setParameter('forumLink', $request->link)
				->setParameter('forumStatus', ForumStatus::Unlocked->value)
				->setParameter('parentId', $request->parentId)
				->setParameter('displayOnIndex', (int) $request->displayOnIndex)
				->setParameter('displaySubforumList', (int) $request->displaySubforumList)
				->setParameter('enableIndexing', (int) $request->enableIndexing)
				->setParameter('enableIcons', (int) $request->enableIcons)
				->setParameter('forumStyle', $request->style)
				->setParameter('forumImage', $request->image)
				->setParameter('forumRules', $request->rules)
				->setParameter('forumRulesLink', $request->rulesLink)
				->setParameter('forumPassword', $request->password)
				->setParameter('topicsPerPage', $request->topicsPerPage)
				->setParameter('forumFlags', $request->flags)
				->executeStatement();

			return (int) $this->connection->lastInsertId();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to insert forum', previous: $e);
		}
	}

	public function update(UpdateForumRequest $request): Forum
	{
		try {
			$sets   = [];
			$params = ['forumId' => $request->forumId];

			if ($request->name !== null) {
				$sets[]              = 'forum_name = :forumName';
				$params['forumName'] = $request->name;
			}

			if ($request->description !== null) {
				$sets[]               = 'forum_desc = :forumDesc';
				$params['forumDesc']  = $request->description;
			}

			if ($request->type !== null) {
				$sets[]              = 'forum_type = :forumType';
				$params['forumType'] = $request->type->value;
			}

			if ($request->parentId !== null) {
				$sets[]              = 'parent_id = :parentId';
				$params['parentId']  = $request->parentId;
			}

			if ($request->link !== null) {
				$sets[]             = 'forum_link = :forumLink';
				$params['forumLink'] = $request->link;
			}

			if ($request->image !== null) {
				$sets[]              = 'forum_image = :forumImage';
				$params['forumImage'] = $request->image;
			}

			if ($request->rules !== null) {
				$sets[]              = 'forum_rules = :forumRules';
				$params['forumRules'] = $request->rules;
			}

			if ($request->rulesLink !== null) {
				$sets[]                  = 'forum_rules_link = :forumRulesLink';
				$params['forumRulesLink'] = $request->rulesLink;
			}

			if ($request->clearPassword === true) {
				$sets[]                  = 'forum_password = :forumPassword';
				$params['forumPassword'] = '';
			} elseif ($request->password !== null) {
				$sets[]                  = 'forum_password = :forumPassword';
				$params['forumPassword'] = $request->password;
			}

			if ($request->style !== null) {
				$sets[]              = 'forum_style = :forumStyle';
				$params['forumStyle'] = $request->style;
			}

			if ($request->topicsPerPage !== null) {
				$sets[]                = 'forum_topics_per_page = :topicsPerPage';
				$params['topicsPerPage'] = $request->topicsPerPage;
			}

			if ($request->flags !== null) {
				$sets[]               = 'forum_flags = :forumFlags';
				$params['forumFlags'] = $request->flags;
			}

			if ($request->displayOnIndex !== null) {
				$sets[]                   = 'display_on_index = :displayOnIndex';
				$params['displayOnIndex'] = (int) $request->displayOnIndex;
			}

			if ($request->displaySubforumList !== null) {
				$sets[]                       = 'display_subforum_list = :displaySubforumList';
				$params['displaySubforumList'] = (int) $request->displaySubforumList;
			}

			if ($request->enableIndexing !== null) {
				$sets[]                   = 'enable_indexing = :enableIndexing';
				$params['enableIndexing'] = (int) $request->enableIndexing;
			}

			if ($request->enableIcons !== null) {
				$sets[]                = 'enable_icons = :enableIcons';
				$params['enableIcons'] = (int) $request->enableIcons;
			}

			if (empty($sets)) {
				return $this->findById($request->forumId) ?? throw new \InvalidArgumentException("Forum {$request->forumId} not found");
			}

			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->where($qb->expr()->eq('forum_id', ':forumId'));

			foreach ($sets as $set) {
				[$col, $placeholder] = explode(' = ', $set, 2);
				$qb->set($col, $placeholder);
			}

			foreach ($params as $key => $value) {
				$qb->setParameter($key, $value);
			}

			$qb->executeStatement();

			return $this->findById($request->forumId) ?? throw new \InvalidArgumentException("Forum {$request->forumId} not found after update");
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update forum', previous: $e);
		}
	}

	public function delete(int $forumId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->eq('forum_id', ':forumId'))
				->setParameter('forumId', $forumId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete forum', previous: $e);
		}
	}

	public function updateTreePosition(int $forumId, int $leftId, int $rightId, int $parentId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('left_id', ':leftId')
				->set('right_id', ':rightId')
				->set('parent_id', ':parentId')
				->where($qb->expr()->eq('forum_id', ':forumId'))
				->setParameter('leftId', $leftId)
				->setParameter('rightId', $rightId)
				->setParameter('parentId', $parentId)
				->setParameter('forumId', $forumId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update tree position', previous: $e);
		}
	}

	public function shiftLeftIds(int $threshold, int $delta): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('left_id', 'left_id + :delta')
				->where($qb->expr()->gte('left_id', ':threshold'))
				->setParameter('delta', $delta)
				->setParameter('threshold', $threshold)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to shift left IDs', previous: $e);
		}
	}

	public function shiftRightIds(int $threshold, int $delta): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('right_id', 'right_id + :delta')
				->where($qb->expr()->gte('right_id', ':threshold'))
				->setParameter('delta', $delta)
				->setParameter('threshold', $threshold)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to shift right IDs', previous: $e);
		}
	}

	public function updateParentId(int $forumId, int $parentId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('parent_id', ':parentId')
				->where($qb->expr()->eq('forum_id', ':forumId'))
				->setParameter('parentId', $parentId)
				->setParameter('forumId', $forumId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to update parent ID', previous: $e);
		}
	}

	public function clearParentsCache(int $forumId): void
	{
		try {
			$qb = $this->connection->createQueryBuilder();
			$qb->update(self::TABLE)
				->set('forum_parents', "'[]'")
				->where($qb->expr()->eq('forum_id', ':forumId'))
				->setParameter('forumId', $forumId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to clear parents cache', previous: $e);
		}
	}

	private function hydrate(array $row): Forum
	{
		return new Forum(
			id:                  (int) $row['forum_id'],
			name:                $row['forum_name'],
			description:         $row['forum_desc'],
			descriptionBitfield: $row['forum_desc_bitfield'],
			descriptionOptions:  (int) $row['forum_desc_options'],
			descriptionUid:      $row['forum_desc_uid'],
			parentId:            (int) $row['parent_id'],
			leftId:              (int) $row['left_id'],
			rightId:             (int) $row['right_id'],
			type:                ForumType::from((int) $row['forum_type']),
			status:              ForumStatus::from((int) $row['forum_status']),
			image:               $row['forum_image'],
			rules:               $row['forum_rules'],
			rulesLink:           $row['forum_rules_link'],
			rulesBitfield:       $row['forum_rules_bitfield'],
			rulesOptions:        (int) $row['forum_rules_options'],
			rulesUid:            $row['forum_rules_uid'],
			link:                $row['forum_link'],
			password:            $row['forum_password'],
			style:               (int) $row['forum_style'],
			topicsPerPage:       (int) $row['forum_topics_per_page'],
			flags:               (int) $row['forum_flags'],
			options:             (int) $row['forum_options'],
			displayOnIndex:      (bool) $row['display_on_index'],
			displaySubforumList: (bool) $row['display_subforum_list'],
			enableIndexing:      (bool) $row['enable_indexing'],
			enableIcons:         (bool) $row['enable_icons'],
			stats: new ForumStats(
				postsApproved:     (int) $row['forum_posts_approved'],
				postsUnapproved:   (int) $row['forum_posts_unapproved'],
				postsSoftdeleted:  (int) $row['forum_posts_softdeleted'],
				topicsApproved:    (int) $row['forum_topics_approved'],
				topicsUnapproved:  (int) $row['forum_topics_unapproved'],
				topicsSoftdeleted: (int) $row['forum_topics_softdeleted'],
			),
			lastPost: new ForumLastPost(
				postId:       (int) $row['forum_last_post_id'],
				posterId:     (int) $row['forum_last_poster_id'],
				subject:      $row['forum_last_post_subject'],
				time:         (int) $row['forum_last_post_time'],
				posterName:   $row['forum_last_poster_name'],
				posterColour: $row['forum_last_poster_colour'],
			),
			pruneSettings: new ForumPruneSettings(
				enabled:   (bool) $row['enable_prune'],
				days:      (int) $row['prune_days'],
				viewed:    (int) $row['prune_viewed'],
				frequency: (int) $row['prune_freq'],
				next:      (int) $row['prune_next'],
			),
			parents: $this->decodeParents($row['forum_parents']),
		);
	}

	private function decodeParents(string $raw): array
	{
		if ($raw === '' || $raw === '[]') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			return $decoded;
		}

		$unserialized = @unserialize($raw);

		return is_array($unserialized) ? $unserialized : [];
	}
}
