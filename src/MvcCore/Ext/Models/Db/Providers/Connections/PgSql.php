<?php

/**
 * MvcCore
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flídr (https://github.com/mvccore/mvccore)
 * @license  https://mvccore.github.io/docs/mvccore/5.0.0/LICENCE.md
 */

namespace MvcCore\Ext\Models\Db\Providers\Connections;

class PgSql 
extends \MvcCore\Ext\Models\Db\Connection
implements	\MvcCore\Ext\Models\Db\Model\IConstants {

	/**
	 * @inheritDocs
	 * @param string $identifierName
	 * @return string
	 */
	public function QuoteName ($identifierName) {
		return '"'.$identifierName.'"';
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

		$transRepeatableRead = ($flags & self::TRANS_ISOLATION_REPEATABLE_READ) > 0;
		$consistentSnapshot = (
			$transRepeatableRead &&
			($flags & self::TRANS_CONSISTENT_SHAPSHOT) > 0
		);

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

		$startTransPropsSeparator = '';
		$snapshotStr = '';
		$writeStr = '';
		
		if ($consistentSnapshot) 
			$snapshotStr = ' WITH CONSISTENT SNAPSHOT';

		if ($this->transReadWriteSupport) {
			if ($readWrite === TRUE) {
				if ($this->autocommit) {
					$this->autocommit = FALSE;
					$sqlItems[] = 'SET SESSION autocommit = 0;';
				}
				$writeStr = ' READ WRITE';
				if ($consistentSnapshot)
					$startTransPropsSeparator = ',';
			} else if ($readWrite === FALSE) {
				$writeStr = ' READ ONLY';
				if ($consistentSnapshot)
					$startTransPropsSeparator = ',';
			}
		}

		$transStartProperties = implode($startTransPropsSeparator, [$snapshotStr, $writeStr]);

		if ($transRepeatableRead) {
			$sqlItems[] = 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;';
		} else if (($flags & self::TRANS_ISOLATION_READ_COMMITTED) > 0) {
			$sqlItems[] = 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;';
		} else if (($flags & self::TRANS_ISOLATION_READ_UNCOMMITTED) > 0) {
			$sqlItems[] = 'SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;';
		} else if (($flags & self::TRANS_ISOLATION_SERIALIZABLE) > 0) {
			$sqlItems[] = 'SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE;';
		}

		if ($name !== NULL) {
			$toolClass = \MvcCore\Application::GetInstance()->GetToolClass();
			$this->transactionName = $toolClass::GetUnderscoredFromPascalCase($name);
			$sqlItems[] = "/* trans_start:{$this->transactionName} */";
		}
		// examples:"START TRANSACTION WITH CONSISTENT SNAPSHOT, READ WRITE;" or
		//			"START TRANSACTION READ WRITE;" or
		//			"START TRANSACTION READ ONLY;" or ...
		$sqlItems[] = "START TRANSACTION{$transStartProperties};";
		
		if ($this->multiStatements) {
			$this->provider->exec(implode(" \n", $sqlItems));
		} else {
			foreach ($sqlItems as $sqlItem)
				$this->provider->exec($sqlItem);
		}

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
		$chain = NULL;
		$chainSql = '';

		if (($flags & self::TRANS_CHAIN) > 0) {
			$chain = TRUE;
			$chainSql = ' AND CHAIN';
		} else if (($flags & self::TRANS_NO_CHAIN) > 0) {
			$chain = FALSE;
			$chainSql = ' AND NO CHAIN';
		}

		if ($this->transactionName !== NULL) 
			$sqlItems[] = "/* trans_commit:{$this->transactionName} */";

		$sqlItems[] = "COMMIT{$chainSql};";

		if (!$chain && !$this->autocommit) {
			$this->autocommit = TRUE;
			$sqlItems[] = 'SET SESSION autocommit = 1;';
		}

		if ($this->multiStatements) {
			$this->provider->exec(implode(" \n", $sqlItems));
		} else {
			foreach ($sqlItems as $sqlItem)
				$this->provider->exec($sqlItem);
		}
		
		if ($chain) {
			$this->inTransaction  = TRUE;
		} else {
			$this->inTransaction  = FALSE;
			$this->transactionName = NULL;
		}

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
		$chain = NULL;
		$chainSql = '';

		if (($flags & self::TRANS_CHAIN) > 0) {
			$chain = TRUE;
			$chainSql = ' AND CHAIN';
		} else if (($flags & self::TRANS_NO_CHAIN) > 0) {
			$chain = FALSE;
			$chainSql = ' AND NO CHAIN';
		}

		if ($this->transactionName !== NULL) 
			$sqlItems[] = "/* trans_rollback:{$this->transactionName} */";

		$sqlItems[] = "ROLLBACK{$chainSql};";

		if (!$chain && !$this->autocommit) {
			$this->autocommit = TRUE;
			$sqlItems[] = 'SET SESSION autocommit = 1;';
		}

		if ($this->multiStatements) {
			$this->provider->exec(implode(" \n", $sqlItems));
		} else {
			foreach ($sqlItems as $sqlItem)
				$this->provider->exec($sqlItem);
		}

		if ($chain) {
			$this->inTransaction  = TRUE;
		} else {
			$this->inTransaction  = FALSE;
			$this->transactionName = NULL;
		}

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
		/*
		$multiStatementsConst = '\PDO::MYSQL_ATTR_MULTI_STATEMENTS';
		$multiStatementsConstVal = defined($multiStatementsConst) 
			? constant($multiStatementsConst) 
			: 0;
		$this->multiStatements = isset($this->options[$multiStatementsConstVal]);
		*/
	}
}
