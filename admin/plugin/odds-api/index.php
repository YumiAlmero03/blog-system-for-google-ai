<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
$query=isset($_GET['new'])?'?new=1':(isset($_GET['id'])&&is_string($_GET['id'])&&preg_match('/^[1-9][0-9]{0,9}$/D',$_GET['id'])?'?id='.$_GET['id']:'');
header('Location: /admin/plugin/odds-api/sports/'.$query,true,303);exit;
