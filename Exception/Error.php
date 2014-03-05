<?php

/*
 * (c) Nicolas Grekas <p@tchwork.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tchwork\Parser\Exception;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Error extends \Exception
{
    public function __construct($message, $line = -1)
    {
        if (0 < $line) {
            $message .= ' on line '.$line;
        }

        $this->message = $message;
    }
}
