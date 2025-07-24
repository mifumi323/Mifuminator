<?php

namespace Mifumi323\Mifuminator;

/**
 * データ解析を行うクラス。
 * ゲームログファイルを解析し、スコアやユーザー統計情報を計算・記録する。
 */
class Analyzer
{
    /**
     * @var DataAccess データアクセスオブジェクト
     */
    private $da;
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
     * @param DataAccess $da データアクセスオブジェクト
     */
    public function __construct($da)
    {
        $this->da = $da;
        $this->db = $da->getDB();
        $this->option = $da->getOption();
    }

    /**
     * ゲームログ全体または差分の解析を行う。
     * @param bool $full 全解析の場合はtrue、差分のみの場合はfalse
     * @return bool 解析が正常に完了した場合はtrue
     */
    public function analyze($full = false)
    {
        $this->db->begin();
        if ($full) {
            $this->db->exec('DELETE FROM score;');
        }

        // 読み替えデータ
        $question_alias = [];
        $ret = $this->db->query('SELECT question_id from_id, CASE WHEN deleted<>0 THEN 0 WHEN equal_to IS NOT NULL THEN equal_to ELSE question_id END to_id FROM question WHERE deleted<>0 OR equal_to IS NOT NULL;');
        foreach ($ret as $row) {
            $question_alias[$row['from_id']] = $row['to_id'];
        }
        $target_alias = [];
        $ret = $this->db->query('SELECT target_id from_id, CASE WHEN deleted<>0 THEN 0 WHEN equal_to IS NOT NULL THEN equal_to ELSE target_id END to_id FROM target WHERE deleted<>0 OR equal_to IS NOT NULL;');
        foreach ($ret as $row) {
            $target_alias[$row['from_id']] = $row['to_id'];
        }

        // ファイル
        $file_list = glob($this->da->getLogFileNameByName('*'));
        rsort($file_list);

        // 解析開始
        if (!$full) {
            $this->analyzeData($file_list, $question_alias, $target_alias, true, true, true);
        } else {
            $this->analyzeData($file_list, $question_alias, $target_alias, false, true, false);
            $this->analyzeData($file_list, $question_alias, $target_alias, true, false, true);
        }

        // いらないのをきれいさっぱり消して完了
        $this->db->exec('DELETE FROM game_state WHERE create_date < DATETIME(\'now\', \'-1 day\');');
        $this->db->exec('
            DELETE FROM score
            WHERE NOT EXISTS (SELECT * FROM target WHERE target.target_id = score.target_id AND deleted = 0 AND equal_to IS NULL)
            OR NOT EXISTS (SELECT * FROM question WHERE question.question_id = score.question_id AND deleted = 0 AND equal_to IS NULL);
        ');
        $this->db->commit();
        $this->db->exec('VACUUM;');

        return true;
    }

    /**
     * ログファイルリストを解析し、スコアやユーザー相関を計算・記録する。
     * @param array $file_list 解析対象のファイルリスト
     * @param array $question_alias 質問IDのエイリアスマップ
     * @param array $target_alias ターゲットIDのエイリアスマップ
     * @param bool $use_auto_black_list 自動ブラックリストを使用するか
     * @param bool $regist_user_correlation ユーザー相関を記録するか
     * @param bool $regist_score スコアを記録するか
     * @return void
     */
    public function analyzeData($file_list, $question_alias, $target_alias, $use_auto_black_list, $regist_user_correlation, $regist_score)
    {
        // ブラックリスト
        if ($use_auto_black_list) {
            $ret = $this->db->query('
                SELECT user_id
                FROM user_black_list
                UNION
                SELECT user_id
                FROM user_statistics
                WHERE correlation < '.$this->option->correlation_threshold.'
            ');
            $user_black_list = array_map(function ($row) {
                return $row['user_id'];
            }, $ret);
        } else {
            $ret = $this->db->query('
                SELECT user_id
                FROM user_black_list
            ');
            $user_black_list = array_map(function ($row) {
                return $row['user_id'];
            }, $ret);
        }

        // ファイルごとに解析
        $count = [];
        $total_score = [];
        foreach ($file_list as $file) {
            if (basename($file) == $this->da->getLogFileName()) {
                continue;
            }
            $this->analyzeFile($file, $count, $total_score, $question_alias, $target_alias, $user_black_list);
        }

        // 回答に対するスコアを計算して記録する
        // 同時にユーザーごとの相関係数用のデータも作る
        $score_power = $this->option->score_max / $this->option->score[Mifuminator::ANSWER_YES];
        $user_correlation = [];
        foreach ($total_score as $target_id => $score_t) {
            foreach ($score_t as $question_id => $score_q) {
                // ユーザーごとの平均と全ユーザーの平均を算出
                $count_value = count($score_q);
                $score_value = 0;
                foreach ($score_q as $user_id => $score_u) {
                    // 1ユーザー分は平均を取って、一人の影響を大きくしないようにする
                    $user_score_value = $score_u / $count[$target_id][$question_id][$user_id];
                    $score_value += $user_score_value;

                    if ($count_value > 1) {
                        $user_correlation[$user_id][$target_id][$question_id]['own'] = $user_score_value;
                    }
                }
                $average_score = $score_value / $count_value;

                // ユーザー数補正を掛けて保存
                if ($regist_score) {
                    $population_power = 1 / (1 + exp(-$this->option->logistic_regression_param * ($count_value - 1)));
                    $final_score = (int) ($average_score * $population_power * $score_power);
                    $this->da->setScore($question_id, $target_id, $final_score);
                }

                // ユーザーごとに自分以外の平均を算出
                if ($regist_user_correlation && $count_value > 1) {
                    foreach ($score_q as $user_id => $score_u) {
                        $user_score_value = $user_correlation[$user_id][$target_id][$question_id]['own'];
                        $other_score_value = ($average_score - $user_score_value / $count_value) * $count_value / ($count_value - 1);
                        $user_correlation[$user_id][$target_id][$question_id]['other'] = $other_score_value;
                    }
                }
            }
        }

        // ユーザーごとの相関係数を計算
        if ($regist_user_correlation) {
            foreach ($user_correlation as $user_id => $target_correlation) {
                // 平均値計算だ
                $own_sum = 0;
                $other_sum = 0;
                $count = 0;
                foreach ($target_correlation as $target_id => $question_correlation) {
                    foreach ($question_correlation as $question_id => $correlation) {
                        $own_sum += $correlation['own'];
                        $other_sum += $correlation['other'];
                        ++$count;
                    }
                }
                $own_average = $own_sum / $count;
                $other_average = $other_sum / $count;

                // 相関係数計算だ
                $covariance = 0;
                $own_variance = 0;
                $other_variance = 0;
                foreach ($target_correlation as $target_id => $question_correlation) {
                    foreach ($question_correlation as $question_id => $correlation) {
                        $covariance += ($correlation['own'] - $own_average) * ($correlation['other'] - $other_average);
                        $own_variance += ($correlation['own'] - $own_average) * ($correlation['own'] - $own_average);
                        $other_variance += ($correlation['other'] - $other_average) * ($correlation['other'] - $other_average);
                    }
                }
                $correlation_value = (0 != $covariance) ? ($covariance / sqrt($own_variance * $other_variance)) : 0;

                // 保存
                $this->da->setUserStatistics($user_id, $count, (int) ($correlation_value * $this->option->correlation_scale));
            }
        }
    }

    /**
     * 1つのログファイルを解析し、スコア集計用配列に加算する。
     * @param string $file 解析対象ファイルパス
     * @param array &$count 回答数集計用配列（参照渡し）
     * @param array &$total_score スコア集計用配列（参照渡し）
     * @param array $question_alias 質問IDのエイリアスマップ
     * @param array $target_alias ターゲットIDのエイリアスマップ
     * @param array $user_black_list ブラックリストユーザーID配列
     * @return void
     */
    public function analyzeFile($file, &$count, &$total_score, $question_alias = [], $target_alias = [], $user_black_list = [])
    {
        $handle = fopen($file, 'r');
        while (false !== ($array = fgetcsv($handle))) {
            if (count($array) < 5) {
                break;
            }
            $timestamp = $array[0];
            $user_id = $array[1];
            $game_id = $array[2];
            $target_id = $array[3];
            if (in_array($user_id, $user_black_list)) {
                continue;
            }
            if (isset($target_alias[$target_id])) {
                $target_id = $target_alias[$target_id];
            }
            if ($target_id <= 0) {
                continue;
            }
            for ($i = 4; $i < count($array); ++$i) {
                list($question_id, $answer) = explode('=', $array[$i]);
                if (isset($question_alias[$question_id])) {
                    $question_id = $question_alias[$question_id];
                }
                if ($question_id <= 0) {
                    continue;
                }
                $count[$target_id][$question_id][$user_id] = ($count[$target_id][$question_id][$user_id] ?? 0) + 1;
                $total_score[$target_id][$question_id][$user_id] = ($total_score[$target_id][$question_id][$user_id] ?? 0) + $this->option->score[trim($answer)];
            }
        }
        fclose($handle);
    }
}
