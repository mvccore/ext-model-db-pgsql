<?php

/**
 * MvcCore
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flidr (https://github.com/mvccore)
 * @license		https://mvccore.github.io/docs/mvccore/5.0.0/LICENSE.md
 */

namespace MvcCore\Ext\Models\Db\Providers\Connections;

class		PgSql 
extends		\MvcCore\Ext\Models\Db\Connection
implements	\MvcCore\Ext\Models\Db\Model\IConstants,
			\MvcCore\Ext\Models\Db\Models\PgSqls\IConstants {
	
	/**
	 * `TRUE` for SQL `READ WRITE` or `READ ONLY` start transaction property support.
	 * @var bool|NULL
	 */
	protected $transReadWriteSupport = NULL;

	/**
	 * `TRUE` for SQL `REPEATABLE READ` or `READ UNCOMMITTED` start transaction property support.
	 * @var bool|NULL
	 */
	protected $transAllIsolationsSupport = NULL;
	
	/**
	 * `TRUE` for SQL `[NOT] DEFERRABLE` start transaction property support.
	 * @var bool|NULL
	 */
	protected $transDeferrableSupport = NULL;

	/**
	 * @inheritDocs
	 * @param string $identifierName
	 * @return string
	 */
	public function QuoteName ($identifierName) {
		if (mb_substr($identifierName, 0, 1) !== '"' && mb_substr($identifierName, -1, 1) !== '"') {
			if (mb_strpos($identifierName, '.') !== FALSE) 
				return '"'.str_replace('.', '"."', $identifierName).'"';
			return '"'.$identifierName.'"';
		}
		return $identifierName;
	}
	
	/**
	 * @inheritDocs
	 * @param int $flags Transaction isolation, read/write mode and consistent snapshot option.
	 * @param string $name String without spaces to identify transaction in logs.
	 * @throws \PDOException|\RuntimeException
	 * @return bool
	 */
	public function BeginTransaction ($flags = 0, $name = NULL) {
		if ($flags === 0) $flags = self::TRANS_READ_WRITE;

		$readWrite = NULL;
		if (($flags & self::TRANS_READ_WRITE) > 0) {
			$readWrite = TRUE;
		} else if (($flags & self::TRANS_READ_ONLY) > 0) {
			$readWrite = FALSE;
		}

		if ($this->inTransaction) {
			$cfg = $this->GetConfig();
			unset($cfg['password']);
			$toolClass = \MvcCore\Application::GetInstance()->GetToolClass();
			throw new \RuntimeException(
				'Connection has opened transaction already ('.($toolClass::EncodeJson($cfg)).').'
			);
		}

		$sqlItems = [];

		$isolationLevel = '';
		$readWrite = '';
		$deferrable = '';
		
		if ($this->transReadWriteSupport) {
			if ($readWrite === TRUE) {
				$readWrite = ' READ WRITE';
			} else if ($readWrite === FALSE) {
				$readWrite = ' READ ONLY';
			}
		}

		if (
			$this->transAllIsolationsSupport && 
			($flags & self::TRANS_ISOLATION_REPEATABLE_READ) > 0
		) {
			$isolationLevel = ' ISOLATION LEVEL REPEATABLE READ';
		} else if (
			($flags & self::TRANS_ISOLATION_READ_COMMITTED) > 0
		) {
			$isolationLevel = ' ISOLATION LEVEL READ COMMITTED';
		} else if (
			$this->transAllIsolationsSupport && 
			($flags & self::TRANS_ISOLATION_READ_UNCOMMITTED) > 0
		) {
			$isolationLevel = ' ISOLATION LEVEL READ UNCOMMITTED';
		} else if (
			($flags & self::TRANS_ISOLATION_SERIALIZABLE) > 0
		) {
			$isolationLevel = ' ISOLATION LEVEL SERIALIZABLE';
		}

		if ($this->transDeferrableSupport) {
			if (($flags & self::TRANS_DEFERRABLE) > 0) {
				$deferrable = ' DEFERRABLE';
			} else if (($flags & self::TRANS_NOT_DEFERRABLE) > 0) {
				$deferrable = ' NOT DEFERRABLE';
			}
		}

		if ($name !== NULL) {
			$toolClass = \MvcCore\Application::GetInstance()->GetToolClass();
			$this->transactionName = $toolClass::GetUnderscoredFromPascalCase($name);
			$sqlItems[] = "/* trans_start:{$this->transactionName} */";
		}
		
		$sqlItems[] = "START TRANSACTION{$isolationLevel}{$readWrite}{$deferrable};";
		
		
		$this->provider->exec(implode("\n", $sqlItems));

		$this->inTransaction = TRUE;

		return TRUE;
	}

	/**
	 * @inheritDocs
	 * @param int $flags Transaction chaininig.
	 * @throws \PDOException
	 * @return bool
	 */
	public function Commit ($flags = 0) {
		if (!$this->inTransaction) return FALSE;
		$sqlItems = [];

		if ($this->transactionName !== NULL) 
			$sqlItems[] = "/* trans_commit:{$this->transactionName} */";

		$sqlItems[] = "COMMIT;";
		
		$this->provider->exec(implode("\n", $sqlItems));
		
		$this->inTransaction  = FALSE;
		$this->transactionName = NULL;

		return TRUE;
	}

	/**
	 * Rolls back a transaction.
	 * @param int $flags Transaction chaininig.
	 * @throws \PDOException
	 * @return bool
	 */
	public function RollBack ($flags = NULL) {
		if (!$this->inTransaction) return FALSE;
		$sqlItems = [];

		if ($this->transactionName !== NULL) 
			$sqlItems[] = "/* trans_rollback:{$this->transactionName} */";

		$sqlItems[] = "ROLLBACK;";

		$this->provider->exec(implode("\n", $sqlItems));
		
		$this->inTransaction  = FALSE;
		$this->transactionName = NULL;
		
		return TRUE;
	}



	/**
	 * @inheritDocs
	 * @see https://stackoverflow.com/questions/7942154/mysql-error-2006-mysql-server-has-gone-away
	 * @param \Throwable $e 
	 * @return bool
	 */
	protected function isConnectionLost (\Throwable $e) {
		return FALSE;
	}

	/**
	 * Set up connection specific properties depends on this driver.
	 * @return void
	 */
	protected function setUpConnectionSpecifics () {
		parent::setUpConnectionSpecifics();
		
		$this->transReadWriteSupport = (
			version_compare($this->version, '7.4', '>=')
		);
		$this->transAllIsolationsSupport = (
			version_compare($this->version, '8.0', '>=')
		);
		$this->transDeferrableSupport = (
			version_compare($this->version, '9.1', '>=')
		);
	}
}