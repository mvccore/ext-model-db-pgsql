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

namespace MvcCore\Ext\Models\Db\Resources\Edits\PgSql;

/**
 * @mixin \MvcCore\Ext\Models\Db\Resources\Edits\PgSql
 */
trait Features {

	/**
	 * Execute SQL code to insert new database table row in transaction, in default database isolation.
	 * @param  int|string  $connNameOrIndex    Connection name or index in system config.
	 * @param  string      $tableName          Database table name.
	 * @param  array       $dataColumns        Data to use in insert clause, keys are 
	 *                                         column names, values are column values.
	 * @param  string      $className          model class full name.
	 * @param  string|NULL $autoIncrColumnName Auto increment column name.
	 * @return array                           First item is boolean result, 
	 *                                         second is affected rows count. 
	 */
	public function Insert ($connNameOrIndex, $tableName, $dataColumns, $className, $autoIncrColumnName) {
		$sqlItems = [];
		$params = [];
		$index = 0;
		$conn = self::GetConnection($connNameOrIndex);

		foreach ($dataColumns as $dataColumnName => $dataColumnValue) {
			$sqlItems[] = $conn->QuoteName($dataColumnName);
			$params[":p{$index}"] = $dataColumnValue;
			$index++;
		}
		
		$tableName = $conn->QuoteName($tableName);
		$insertSql = "INSERT INTO {$tableName} (" 
			. implode(", ", $sqlItems) 
			. ") VALUES (" 
			. implode(", ", array_keys($params)) 
			. ");";
		
		if ($autoIncrColumnName !== NULL) {
			$autoIncrColumnName = $conn->QuoteName($autoIncrColumnName);
			$newIdName = $conn->QuoteName("new_id");
			// most universal case for any database structure or database engine version:
			$newIdSelectSql = "SELECT MAX({$autoIncrColumnName}) "
				. "AS {$newIdName} FROM {$tableName};";
		}

		$success = FALSE;
		$newId = NULL;
		$error = NULL;

		$transName = 'insert_'.str_replace('\\', '_', $className);
		try {
			$conn->BeginTransaction(8 | 16, $transName); // 8 means serializable, 16 means read write

			$insertReader = $conn
				->Prepare($insertSql)
				->Execute($params);

			$success = $insertReader->GetExecResult();
			$affectedRows = $insertReader->GetRowsCount();

			if ($autoIncrColumnName !== NULL)
				$newId = $conn
					->Prepare($newIdSelectSql)
					->FetchOne()
					->ToScalar('new_id', 'int');

			$conn->Commit();

			$success = TRUE;
		} catch (\Throwable $e) {
			$affectedRows = 0;
			$newId = NULL;
			$error = $e;
			if ($conn && $conn->InTransaction())
				$conn->RollBack();
		}

		return [
			$success, 
			$affectedRows,
			$newId,
			$error
		];
	}
}