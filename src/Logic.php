<?php

namespace Mifumi323\Mifuminator;

/**
 * ゲームの推論・選択ロジックを提供するクラス。
 * 候補選定やスコア計算などのロジックを実装する。
 */
class Logic
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
     * 候補リストからスコア等で最適なターゲットID群を返す。
     * @param array $targets 候補ターゲット配列
     * @param int $max 最大数
     * @param int $min 最小数
     * @param int $cutoff_difference スコア差の閾値
     * @return array 最適なターゲットID配列
     */
    public function getBestTargetIDs($targets, $max, $min, $cutoff_difference)
    {
        $highscore = 0;
        $i = 0;
        $result = [];
        foreach ($targets as $target) {
            if ($i >= $min) {
                if ($target['score'] <= 0) {
                    break;
                }
                if ($target['score'] <= $highscore - $cutoff_difference) {
                    break;
                }
                if ($i >= $max) {
                    break;
                }
            }
            $result[] = $target['target_id'];
            if (0 == $i) {
                $highscore = $target['score'];
            }
            ++$i;
        }

        return $result;
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
     * オプション設定オブジェクトを取得する。
     * @return Option オプション設定オブジェクト
     */
    public function getOption()
    {
        return $this->option;
    }

    public function getQuestionScoreSqlDivideHalf($temp_targets)
    {
        $score_sql = '
            (
                SELECT
                    MIN(
                        SUM(CASE WHEN score > 0 THEN 1 ELSE 0 END)
                        ,
                        SUM(CASE WHEN score < 0 THEN 1 ELSE 0 END)
                    )
                FROM score
                WHERE score.question_id = question.question_id
                '.(count($temp_targets) > 1 ? 'AND target_id IN ('.implode(',', $temp_targets).')' : '').'
            )
        ';

        return $score_sql;
    }

    public function getQuestionScoreSqlDivideTop2($temp_targets)
    {
        if (count($temp_targets) > 1) {
            $score_sql = '
                ABS((
                    SELECT
                        CASE WHEN a IS NOT NULL THEN
                            CASE WHEN b IS NOT NULL THEN
                                ABS(a-b)*10
                            ELSE
                                ABS(a)
                            END
                        ELSE
                            CASE WHEN b IS NOT NULL THEN
                                ABS(b)
                            ELSE
                                0
                            END
                        END
                        score
                    FROM (
                        SELECT
                            (
                                SELECT score
                                FROM score
                                WHERE score.question_id = question.question_id
                                AND score.target_id = '.$temp_targets[0].'
                            ) a,
                            (
                                SELECT score
                                FROM score
                                WHERE score.question_id = question.question_id
                                AND score.target_id = '.$temp_targets[1].'
                            ) b
                    ) pp
                ))
            ';
        } else {
            $score_sql = '0';
        }

        return $score_sql;
    }

    public function getQuestionScoreSqlManyNo($temp_targets)
    {
        $score_sql = '
            (
                SELECT
                    SUM(CASE WHEN score < 0 THEN 1 ELSE 0 END)
                FROM score
                WHERE score.question_id = question.question_id
                '.(count($temp_targets) > 0 ? 'AND target_id IN ('.implode(',', $temp_targets).')' : '').'
            )
        ';

        return $score_sql;
    }

    public function getQuestionScoreSqlManyYes($temp_targets)
    {
        $score_sql = '
            (
                SELECT
                    SUM(CASE WHEN score > 0 THEN 1 ELSE 0 END)
                FROM score
                WHERE score.question_id = question.question_id
                '.(count($temp_targets) > 0 ? 'AND target_id IN ('.implode(',', $temp_targets).')' : '').'
            )
        ';

        return $score_sql;
    }

    public function getQuestionScoreSqlUnknown($temp_targets)
    {
        if (count($temp_targets) > 0) {
            $target_count = 'COALESCE(SUM(CASE WHEN target_id IN ('.implode(',', $temp_targets).') THEN -(SELECT COUNT(question_id) FROM question) ELSE 0 END), 0)';
        } else {
            $target_count = '';
        }

        return '
            (
                SELECT '.$target_count.'-(CASE WHEN SUM(CASE WHEN score>0 THEN 1 ELSE 0 END)>0 AND SUM(CASE WHEN score<0 THEN 1 ELSE 0 END)>0 THEN COUNT(*) ELSE 0 END)
                FROM score
                WHERE score.question_id = question.question_id
            )
        ';
    }

    public function getQuestionScoreSqlWellKnown($temp_targets)
    {
        $score_sql = '
            (
                SELECT
                    SUM(CASE WHEN score <> 0 THEN 1 ELSE 0 END)
                FROM score
                WHERE score.question_id = question.question_id
                '.(count($temp_targets) > 0 ? 'AND target_id IN ('.implode(',', $temp_targets).')' : '').'
            )
        ';

        return $score_sql;
    }

    public function getTargetScoreSql($question_answer_history, $score)
    {
        $whenthen = '';
        $qcsv = '';
        foreach ($question_answer_history as $question_id => $answer) {
            $qscore = $score[$answer];
            if (0 == $qscore) {
                continue;
            }
            $whenthen .= "\nWHEN $question_id THEN $qscore";
            if (strlen($qcsv) > 0) {
                $qcsv .= ',';
            }
            $qcsv .= $question_id;
        }
        if (0 == strlen($qcsv)) {
            return '0';
        }

        return '
            COALESCE((
                SELECT
                    SUM(score * CASE question_id
                    '.$whenthen.'
                    END
                    )
                FROM score
                WHERE score.target_id = target.target_id
                AND score.question_id IN ('.$qcsv.')
            ), 0)
        ';
    }

    public function guessTarget($question_answer_history, $max, $min, $except_target_ids, $cutoff_difference, $score)
    {
        $except_target_sql = '';
        if (count($except_target_ids ?? []) > 0) {
            $except_target_sql = 'AND target_id NOT IN ('.implode(',', $except_target_ids).')';
        }
        $target_additional_column = $this->getOption()->target_additional_column ?? '';
        if (strlen($target_additional_column) > 0) {
            $target_additional_column = ','.$target_additional_column;
        }
        $ret = $this->getDB()->query('
            SELECT
                target_id,
                content,
                '.$this->getTargetScoreSql($question_answer_history, $score).' score
                '.$target_additional_column.'
            FROM target
            WHERE deleted = 0
            AND equal_to IS NULL
            '.$except_target_sql.'
            ORDER BY score DESC, RANDOM()
            LIMIT '.$max.'
        ');
        $result = [];
        $i = 0;
        $highscore = 0;
        foreach ($ret as $row) {
            if ($i >= $min) {
                if ($row['score'] <= 0) {
                    break;
                }
                if ($row['score'] <= $highscore - $cutoff_difference) {
                    break;
                }
            }
            $result[] = $row;
            if (0 == $i) {
                $highscore = $row['score'];
            }
            ++$i;
        }

        return $result;
    }

    public function selectNextQuestion($question_answer_history = [], $temp_targets = [], $avoid_same_answer_number = 0, $try_unknown_question_rate = 0)
    {
        // ワンパターン回避
        if ($avoid_same_answer_number > 0 && count($question_answer_history) >= $avoid_same_answer_number) {
            $yes = true;
            $no = true;
            $dontknow = true;
            $answers = array_values($question_answer_history);
            for ($i = 1; $i <= $avoid_same_answer_number; ++$i) {
                switch ($answers[count($answers) - $i]) {
                    case Mifuminator::ANSWER_YES:
                    case Mifuminator::ANSWER_PROBABLY:
                        $no = false;
                        $dontknow = false;
                        break;
                    case Mifuminator::ANSWER_NO:
                    case Mifuminator::ANSWER_PROBABLY_NOT:
                        $yes = false;
                        $dontknow = false;
                        break;
                    case Mifuminator::ANSWER_DONT_KNOW:
                        $yes = false;
                        $no = false;
                        break;
                    default:
                        $yes = false;
                        $no = false;
                        $dontknow = false;
                        break;
                }
            }
            if ($yes) {
                $question = $this->selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, 'getQuestionScoreSqlManyNo');
            } elseif ($no) {
                $question = $this->selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, 'getQuestionScoreSqlManyYes');
            } elseif ($dontknow) {
                $question = $this->selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, 'getQuestionScoreSqlWellKnown');
            } else {
                $question = ['score' => 0];
            }
            if (0 != $question['score']) {
                return $question;
            }
        }

        // ランダムで未知の質問を出す
        if (mt_rand(0, 99) < $try_unknown_question_rate) {
            return $this->selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, 'getQuestionScoreSqlUnknown');
        }

        // 判断に有利な質問を選ぶ
        if (2 == count($temp_targets)) {
            $question = $this->selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, 'getQuestionScoreSqlDivideTop2');
            if (0 != $question['score']) {
                return $question;
            }
        }
        $question = $this->selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, 'getQuestionScoreSqlDivideHalf');
        if (0 != $question['score']) {
            return $question;
        }
        $question = $this->selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, 'getQuestionScoreSqlDivideTop2');
        if (0 != $question['score']) {
            return $question;
        }

        // どうしようもないので次回にご期待ください
        return $this->selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, 'getQuestionScoreSqlUnknown');
    }

    public function selectNextQuestionWithScoreFunction($question_answer_history, $temp_targets, $score_function)
    {
        $function = [$this, $score_function];
        $except_sql = '';
        if (count($question_answer_history) > 0) {
            $except_sql = 'AND question_id NOT IN ('.implode(',', array_keys($question_answer_history)).')';
        }
        $question_additional_column = $this->getOption()->question_additional_column ?? '';
        if (strlen($question_additional_column) > 0) {
            $question_additional_column = ','.$question_additional_column;
        }
        $score_sql = $function($temp_targets);
        $ret = $this->getDB()->query('
            SELECT
                question_id,
                content,
                '.$score_sql.' score
                '.$question_additional_column.'
            FROM question
            WHERE deleted = 0
            AND equal_to IS NULL
            '.$except_sql.'
            ORDER BY score DESC, RANDOM()
            LIMIT 1;
        ');
        $question = $ret[0];
        $question['function'] = $score_function;
        $question['score_sql'] = $score_sql;

        return $question;
    }
}
