<?php
// خروج العضو: إنهاء جلسة العضو فقط (لا تمسّ جلسة الموظّف إن وُجدت)
require __DIR__ . '/db.php';
unset($_SESSION['member']);
header('Location: member_login.php');
