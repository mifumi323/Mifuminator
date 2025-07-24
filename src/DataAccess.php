<?php

namespace Mifumi323\Mifuminator;

/**
 * データベースや設定オプションへのアクセスを提供するクラス。
 * 各種データの追加・取得・記録・ログ出力などを行う。
 */
class DataAccess
{
    /**
     * @var Database データベースオブジェクト
     */
    private $db;
    /**
     * @var Option オプション設定オブジェクト
     */
    private $option;

    /**
     * コンストラクタ
     * @param Database $db データベースオブジェクト
     * @param Option $option オプション設定オブジェクト
     */
    public function __construct($db, $option)
    {
        $this->db = $db;
        $this->option = $option;
    }

    /**
     * ターゲットを追加する。
     * @param string $target ターゲット内容
     * @param int|null $user_id ユーザーID（省略可）
     * @return void
     */
    public function addTarget($target, $user_id = null)
    {
        $this->db->insert('target', ['content' => $target, 'create_user_id' => $user_id, 'update_user_id' => $user_id]);
    }

    /**
     * 質問を追加する。
     * @param string $question 質問内容
     * @param int|null $user_id ユーザーID（省略可）
     * @return void
     */
    public function addQuestion($question, $user_id = null)
    {
        $this->db->insert('question', ['content' => $question, 'create_user_id' => $user_id, 'update_user_id' => $user_id]);
    }

    /**
     * データベースオブジェクトを取得する。
     * @return Database データベースオブジェクト
     */
    public function getDB()
    {
        return $this->db;
    }

    /**
     * ログファイル名を取得する。
     * @param int|null $time タイムスタンプ（省略時は現在時刻）
     * @return string ログファイル名
     */
    public function getLogFileName($time = null)
    {
        if ($time === null) {
            $time = time();
        }

        return $this->getLogFileNameByName(date('Ymd', $time));
    }

    /**
     * 指定名のログファイル名を取得する。
     * @param string $name ファイル名（日付など）
     * @return string ログファイル名
     */
    public function getLogFileNameByName($name)
    {
        return $this->option->log_dir.$name.'.log';
    }

    /**
     * オプション設定オブジェクトを取得する。
     * @return Option オプション設定オブジェクト
     */
    public function getOption()
    {
        return $this->option;
    }

    /**
     * スコアを記録する。
     * @param int $question_id 質問ID
     * @param int $target_id ターゲットID
     * @param int $score スコア値
     * @param bool $replace 既存レコードを置換するか（デフォルトtrue）
     * @return void
     */
    public function setScore($question_id, $target_id, $score, $replace = true)
    {
        $this->db->insert('score', ['question_id' => $question_id, 'target_id' => $target_id, 'score' => $score], [], $replace, false);
    }

    /**
     * ユーザー統計情報を記録する。
     * @param int $user_id ユーザーID
     * @param int $answer_count 回答数
     * @param int $correlation 相関係数
     * @return void
     */
    public function setUserStatistics($user_id, $answer_count, $correlation)
    {
        $values = [
            ':user_id' => $user_id,
            ':answer_count' => $answer_count,
            ':correlation' => $correlation,
        ];
        $sql = '
            INSERT OR REPLACE
            INTO user_statistics
            (user_id, answer_count, correlation, update_date)
            VALUES (:user_id, :answer_count, :correlation, CURRENT_TIMESTAMP)
        ';
        $this->db->exec($sql, $values);
    }

    /**
     * ログファイルに回答データを書き込む。
     * 必要に応じてスコアもDBに記録する。
     * @param int $user_id ユーザーID
     * @param int $game_id ゲームID
     * @param int $target_id ターゲットID
     * @param array $question_answer_list 質問ID=>回答の配列
     * @param int|null $time タイムスタンプ（省略時は現在時刻）
     * @param bool $insertToDB スコアをDBに記録するか
     * @return void
     */
    public function writeLog($user_id, $game_id, $target_id, $question_answer_list, $time = null, $insertToDB = false)
    {
        if ($time === null) {
            $time = time();
        }
        $line = date('c', time()).','.$user_id.','.$game_id.','.$target_id;
        if ($insertToDB) {
            $this->db->begin();
        }
        foreach ($question_answer_list as $question_id => $answer) {
            $line .= ','.$question_id.'='.$answer;
            if ($insertToDB) {
                $this->setScore($question_id, $target_id, (int) (0.5 * $this->option->score[$answer] * $this->option->score_max / $this->option->score[Mifuminator::ANSWER_YES]), false);
            }
        }
        if ($insertToDB) {
            $this->db->commit();
        }
        file_put_contents($this->getLogFileName($time), $line."\n", FILE_APPEND);
    }
}
