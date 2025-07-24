<?php

namespace Mifumi323\Mifuminator;

/**
 * ゲームの進行中に発生する無効なターゲットに関する例外クラス。
 */
class GameInvalidTargetException extends \Exception
{
    /**
     * @param string|null $message エラーメッセージ
     * @param int $code エラーコード
     * @param \Exception|null $previous 前の例外
     */
    public function __construct($message = null, $code = 0, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
