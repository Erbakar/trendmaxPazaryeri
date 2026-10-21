<?php
/**
 * OzyConnect Panel — Root Redirector
 * 
 * Bu dosya root dizinden public/ klasörüne yönlendirme yapar.
 * Apache .htaccess zaten bunu yapıyor ama bazı sunucularda
 * index.php dosyası öncelikli olduğu için bu dosya gerekli.
 */

// public/index.php'ye yönlendir
require_once __DIR__ . '/public/index.php';
