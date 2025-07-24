<?php

namespace Mifumi323\Mifuminator;

/**
 * SQLiteデータベースへの接続・操作を提供するクラス。
 */
class Database
{
    /**
     * @var string データベースファイルパス
     */
    private $db_file_path;
    /**
     * @var \PDO PDOインスタンス
     */
    private $db;

    /**
     * コンストラクタ
     * @param string $db_file_path データベースファイルパス
     */
    public function __construct($db_file_path)
    {
        $this->db_file_path = $db_file_path;
        $this->db = new \PDO('sqlite:'.$db_file_path);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('RANDOM', 'mt_rand', 0);
    }

    /**
     * トランザクション開始
     * @return bool
     */
    public function begin()
    {
        return $this->db->beginTransaction();
    }

    /**
     * コミット
     * @return bool
     */
    public function commit()
    {
        return $this->db->commit();
    }

    /**
     * SQL実行
     * @param string $sql SQL文
     * @param array $params パラメータ
     * @return \PDOStatement|false
     */
    public function exec($sql, $params = [])
    {
        $statement = $this->prepare($sql);
        if ($statement !== false) {
            $statement->execute($params);
        }

        return $statement;
    }

    /**
     * データベースオブジェクトを取得
     * @return \PDO
     */
    public function getObject()
    {
        return $this->db;
    }

    /**
     * レコード挿入
     * @param string $table テーブル名
     * @param array $params 挿入データ
     * @param array $sqlparams SQLパラメータ
     * @param bool $tryReplace REPLACE句を使うか
     * @param bool $throwOnConflict 衝突時に例外を投げるか
     * @return bool
     */
    public function insert($table, $params, $sqlparams = [], $tryReplace = false, $throwOnConflict = true)
    {
        $values = [];
        foreach ($params as $key => $value) {
            $values[':'.$key] = $value;
        }
        $columns = array_keys($params) + array_keys($sqlparams);
        $sql = 'INSERT'.($tryReplace ? ' OR REPLACE' : ($throwOnConflict ? '' : ' OR IGNORE')).' INTO '.$table.' ('.implode(',', $columns).') VALUES ('.implode(',', array_keys($values) + array_values($sqlparams)).')';
        $statement = $this->prepare($sql);
        if ($statement === false) {
            return false;
        } else {
            $statement->execute($values);

            return true;
        }
    }

    /**
     * プリペアドステートメントの準備
     * @param string $statement SQL文
     * @param array $driver_options ドライバオプション
     * @return \PDOStatement|false
     */
    public function prepare($statement, $driver_options = [])
    {
        return $this->db->prepare($statement, $driver_options);
    }

    /**
     * クエリの実行
     * @param string $sql SQL文
     * @param array $params パラメータ
     * @return array|false
     */
    public function query($sql, $params = [])
    {
        $statement = $this->exec($sql, $params);
        if ($statement === false) {
            return false;
        } else {
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        }
    }

    /**
     * ロールバック
     * @return bool
     */
    public function rollback()
    {
        return $this->db->rollback();
    }
}
