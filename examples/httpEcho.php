<?php
declare(strict_types=1);

/**
 * 最简 echo 服务：原样返回请求体，供 curl live 测试
 * 运行：php -S 127.0.0.1:18099 examples/httpEcho.php
 */

header('Content-Type: application/json');
echo file_get_contents('php://input');
