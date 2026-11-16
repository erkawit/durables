# แผนการปรับปรุงหน้า Reports.php

## ปัญหาที่พบ:

1. ไม่มีคำอธิบายในแต่ละส่วนสถิติ
2. ไม่มีปุ่มสำหรับออกรายงาน PDF
3. UI ดูธรรมดา ไม่โดดเด่น
4. ข้อมูลไม่แสดงเพราะปี 2026 (ข้อมูลอยู่ในปีเก่ากว่า)

## การปรับปรุงที่จะทำ:

### 1. เพิ่มคำอธิบายและป้ายคำแนะนำ (Instructions Banner)

- คำอธิบายการใช้งานหน้ารายงาน
- อธิบายความหมายของสถิติแต่ละประเภท

### 2. ปรับปรุง UI/UX

- เพิ่ม gradient สีสวยงามให้กับ stats cards
- เพิ่ม hover effects
- ปรับ icons ให้โดดเด่นขึ้น
- เพิ่ม section headers ที่สวยงาม

### 3. เพิ่มฟังก์ชัน PDF Export

- ปุ่ม "ออกรายงาน PDF" สำหรับแต่ละสถิติ:
  - สรุปภาพรวม (Overview Summary)
  - สถิติรายเดือน (Monthly Statistics)
  - วัสดุยอดนิยม (Top Materials)
  - สถิติตามส่วนงาน (Section Statistics)
  - รายละเอียดการเบิกตามส่วนงาน (Material by Section)
  - รายการเบิกล่าสุด (Recent Requests)

### 4. เพิ่มตัวกรอง (Filters)

- เลือกปีสำหรับดูข้อมูล
- เลือกช่วงเวลา (เดือน/ไตรมาส)

### 5. ใช้ mPDF Library

- ติดตั้งผ่าน composer หรือใช้ manual download
- สร้าง template สำหรับ PDF แต่ละประเภท

## ไฟล์ที่จะสร้าง/แก้ไข:

1. reports.php - หน้าหลัก (ปรับปรุง UI + เพิ่ม PDF buttons)
2. export_pdf.php - ไฟล์สำหรับสร้าง PDF แยกตามประเภท
3. composer.json - สำหรับติดตั้ง mPDF (ถ้ายังไม่มี)

## สีและธีมที่ใช้:

- Sky/Blue theme (sky-500, sky-600) - consistency with other pages
- Gradient backgrounds for cards
- Icons with colored backgrounds
- Smooth hover transitions
