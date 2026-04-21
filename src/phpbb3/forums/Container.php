<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

namespace phpbb;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
* Application container facade providing typed access to core phpBB services.
*
* Replaces scattered global $user, $config, $db, $auth, etc. with a single
* injectable object. Bridge between the legacy procedural layer and modern DI.
*/
class Container
{
	/** @var ContainerInterface */
	protected $container;

	public function __construct(ContainerInterface $container)
	{
		$this->container = $container;
	}

	/** @return \phpbb\user */
	public function getUser(): \phpbb\user
	{
		return $this->container->get('user');
	}

	/** @return \phpbb\config\config */
	public function getConfig(): \phpbb\config\config
	{
		return $this->container->get('config');
	}

	/** @return \phpbb\db\driver\driver_interface */
	public function getDb(): \phpbb\db\driver\driver_interface
	{
		return $this->container->get('dbal.conn');
	}

	/** @return \phpbb\auth\auth */
	public function getAuth(): \phpbb\auth\auth
	{
		return $this->container->get('auth');
	}

	/** @return \phpbb\language\language */
	public function getLanguage(): \phpbb\language\language
	{
		return $this->container->get('language');
	}

	/** @return \phpbb\template\template */
	public function getTemplate(): \phpbb\template\template
	{
		return $this->container->get('template');
	}

	/** @return \phpbb\request\request_interface */
	public function getRequest(): \phpbb\request\request_interface
	{
		return $this->container->get('request');
	}

	/** @return \phpbb\cache\service */
	public function getCache(): \phpbb\cache\service
	{
		return $this->container->get('cache');
	}

	/** @return \phpbb\log\log_interface */
	public function getLog(): \phpbb\log\log_interface
	{
		return $this->container->get('log');
	}

	/** @return \phpbb\event\dispatcher_interface */
	public function getDispatcher(): \phpbb\event\dispatcher_interface
	{
		return $this->container->get('dispatcher');
	}

	/** @return \phpbb\path_helper */
	public function getPathHelper(): \phpbb\path_helper
	{
		return $this->container->get('path_helper');
	}

	/** @return \phpbb\filesystem\filesystem_interface */
	public function getFilesystem(): \phpbb\filesystem\filesystem_interface
	{
		return $this->container->get('filesystem');
	}

	/** @return \phpbb\extension\manager */
	public function getExtensionManager(): \phpbb\extension\manager
	{
		return $this->container->get('ext.manager');
	}

	/**
	* Generic getter for services not covered by typed accessors.
	*
	* @param string $id Service identifier
	* @return mixed
	*/
	public function get(string $id): mixed
	{
		return $this->container->get($id);
	}

	public function getParameter(string $name): mixed
	{
		return $this->container->getParameter($name);
	}

	public function has(string $id): bool
	{
		return $this->container->has($id);
	}
}