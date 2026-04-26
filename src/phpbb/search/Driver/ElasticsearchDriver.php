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

namespace phpbb\search\Driver;

use phpbb\api\DTO\PaginationContext;
use phpbb\search\DTO\SearchQuery;
use phpbb\user\DTO\PaginatedResult;

final class ElasticsearchDriver extends LikeDriver
{
	public function __construct(
		\Doctrine\DBAL\Connection $connection,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
		parent::__construct($connection);
	}

	public function search(SearchQuery $query, PaginationContext $ctx): PaginatedResult
	{
		$this->logger->warning('Elasticsearch driver not implemented; falling back to LikeDriver', ['query' => $query->keywords]);

		return parent::search($query, $ctx);
	}
}
