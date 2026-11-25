<?php
// ===== Festival webapp config =====
// ปรับค่าตามต้องการ ไฟล์นี้ไม่ต้องเขียนทับเวลาอัปเดตเวอร์ชันใหม่
date_default_timezone_set('Asia/Bangkok');

define('APP_VERSION', '1.0');
define('DATA_DIR', __DIR__ . '/data');

// เทศกาลที่เปิด (key => settings)  — เพิ่มเทศกาลใหม่ได้ที่นี่
$FESTIVALS = [
  'loykrathong-2569' => [
    'title'    => 'ลอยกระทงออนไลน์ 2569',
    'open'     => true,          // false = ปิดรับกระทงใหม่ (ยังดูได้)
    'max_len'  => 150,           // ความยาวคำอธิษฐานสูงสุด
  ],
];

define('RATE_LIMIT_SEC', 10);    // ลอยได้ 1 ใบต่อ 10 วินาที ต่อ IP (ถ้าทั้งโรงงานออกผ่าน IP เดียว ให้ลดเป็น 0)
define('MAX_ITEMS', 5000);

// คำไม่สุภาพ (ปฏิเสธการส่ง) — เพิ่มได้
$BAD_WORDS = ['ควย','เหี้ย','สัส','ไอ้สัตว์','เย็ด','หี','fuck','shit','bitch'];
