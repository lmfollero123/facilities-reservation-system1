@echo off
cd /d "E:\Capstone_project\facilities_reservation_system"
"C:\xampp\php\php.exe" "E:\Capstone_project\facilities_reservation_system\scripts\archive_documents.php"  >> "E:\Capstone_project\facilities_reservation_system\storage\task_logs\FRS_-_Archive_Documents__Daily_.log" 2>&1
