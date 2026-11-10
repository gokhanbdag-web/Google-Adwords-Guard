<?php
declare(strict_types=1);
return ['driver'=>'mysql','host'=>env('DB_HOST','127.0.0.1'),'port'=>(int)env('DB_PORT',3306),'database'=>env('DB_DATABASE','adguard'),'username'=>env('DB_USERNAME','root'),'password'=>env('DB_PASSWORD',''),'charset'=>'utf8mb4'];
