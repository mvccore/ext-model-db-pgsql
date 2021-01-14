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

namespace MvcCore\Ext\Models\Db\Providers\Resources\PgSqls;

trait Manipulation {

	/**
	 * Execute SQL code to insert new database table row in transaction, in default database isolation.
	 * @param int|string $connNameOrIndex	Connection name or index in system config.
	 * @param string $tableName				Database table name.
	 * @param array $dataColumns			Data to use in insert clause, keys are 
	 *										column names, values are column values.
	 * @param string $className				model class full name.
	 * @param string $autoIncrColumnName	Auto increment column name.
	 * @return array						First item is boolean result, 
	 *										second is affected rows count. 
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
		
		$autoIncrColumnName = $conn->QuoteName($autoIncrColumnName);
		$newIdName = $conn->QuoteName("new_id");
		$newIdSelectSql = "SELECT MAX({$autoIncrColumnName}) "
			. "AS {$newIdName} FROM {$tableName};"

		$success = FALSE;
		$error = NULL;

		$transName = 'INSERT:'.str_replace('\\', '_', $className);
		try {
			$conn->BeginTransaction(16, $transName); // 16 means read write

			$insertReader = $conn
				->Prepare($sql)
				->Execute($params);

			$success = $insertReader->GetExecResult();
			$affectedRows = $insertReader->GetRowsCount();

			$newId = $conn
				->Prepare($newIdSelectSql)
				->FetchOne()
				->ToScalar('new_id', 'id');

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

	protected function getPrimaryKeyColumn
}