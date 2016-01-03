<?php
namespace MifuminLib\Mifuminator;

class Database {
    private $db_file_path;
    private $db;

    public function __construct($db_file_path)
    {
        $this->db_file_path = $db_file_path;
        $this->db = new \PDO('sqlite:'.$db_file_path);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('RANDOM', 'mt_rand', 0);
    }

    // トランザクション開始
    public function begin()
    {
        return $this->db->beginTransaction();
    }

    // コミット
    public function commit()
    {
        return $this->db->commit();
    }

    // 実行
    public function exec($sql, $params=[])
    {
        $statement = $this->prepare($sql);
        if ($statement!==FALSE) {
            $statement->execute($params);
        }
        return $statement;
    }

    // データベースオブジェクトを取得
    public function getObject()
    {
        return $this->db;
    }

    // 挿入
    public function insert($table, $params, $sqlparams=[], $tryReplace=FALSE, $throwOnConflict=TRUE)
    {
        $values = [];
        foreach ($params as $key => $value) {
            $values[':'.$key] = $value;
        }
        $columns = array_keys($params)+array_keys($sqlparams);
        $sql = 'INSERT'.($tryReplace?' OR REPLACE':($throwOnConflict?'':' OR IGNORE')).' INTO '.$table.' ('.implode(',', $columns).') VALUES ('.implode(',', array_keys($values)+array_values($sqlparams)).')';
        $statement = $this->prepare($sql);
        if ($statement===FALSE) {
            return FALSE;
        }else {
            $statement->execute($values);
            return TRUE;
        }
    }

    // プリペアドステートメントの準備
    public function prepare($statement, $driver_options=[])
    {
        return $this->db->prepare($statement, $driver_options);
    }

    // クエリの実行
    public function query($sql, $params=[])
    {
        $statement = $this->exec($sql, $params);
        if ($statement===FALSE) {
            return FALSE;
        }else {
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        }
    }

    // ロールバック
    public function rollback()
    {
        return $this->db->rollback();
    }
}