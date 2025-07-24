<?php

namespace Mifumi323\Mifuminator;

/**
 * ゲームの各種設定値を保持するクラス。
 * スコアや閾値、学習パラメータなどのオプションを管理する。
 */
class Option
{
    /**
     * @var string ログファイルのディレクトリパス
     */
    public $log_dir;

    /**
     * @var array 回答内容ごとのスコア
     */
    public $score = [
        Mifuminator::ANSWER_YES => 10,
        Mifuminator::ANSWER_NO => -10,
        Mifuminator::ANSWER_DONT_KNOW => 0,
        Mifuminator::ANSWER_PROBABLY => 1,
        Mifuminator::ANSWER_PROBABLY_NOT => -1,
    ];

    /**
     * @var int 統計スコアの最大値
     */
    public $score_max = 100;

    /**
     * @var int 候補のうちトップからこれだけ引き離されていると候補から除外
     */
    public $cutoff_difference = 2000;

    /**
     * @var int 未知の質問を投げかける確率(%)
     */
    public $try_unknown_question_rate = 5;

    /**
     * @var float ロジスティック回帰の計数
     */
    public $logistic_regression_param = 0.054;

    /**
     * @var array 何回目で答えを言うか
     */
    public $suggest_timings = [20, 40, 50];

    /**
     * @var bool 最後の1問を学習優先で選ぶ機能を使うか
     */
    public $use_final_learning = true;

    /**
     * @var int この回数だけ連続で同じ回答をした場合に、今までと違う回答を期待できる質問を探す(0で無効)
     */
    public $avoid_same_answer_number = 4;

    /**
     * @var int 質問指定の学習時に提示する候補数
     */
    public $learn_target_max = 10;

    /**
     * @var int 質問指定の学習時に提示する候補に交ぜる学習データ少な目のデータ数
     */
    public $learn_target_unknown = 1;

    /**
     * @var string|null 質問の追加項目(SQL文)
     */
    public $question_additional_column = null;

    /**
     * @var string|null 対象の追加項目(SQL文)
     */
    public $target_additional_column = null;

    /**
     * @var int 相関係数の倍率
     */
    public $correlation_scale = 100;

    /**
     * @var int 有効なユーザーの相関係数の下限
     */
    public $correlation_threshold = 10;
}
