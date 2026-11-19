document.addEventListener('DOMContentLoaded', function () {
    if (window.__flash) {
        var f = window.__flash;
        var icon = f.type === 'error' ? 'error' : (f.type === 'warning' ? 'warning' : 'success');
        Swal.fire({
            icon: icon,
            title: f.message,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2800,
            timerProgressBar: true,
        });
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var message = form.getAttribute('data-confirm') || 'ยืนยันการทำรายการนี้หรือไม่?';
            Swal.fire({
                title: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#d33',
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    var scoreForm = document.getElementById('scoreForm');
    if (scoreForm) {
        scoreForm.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'บันทึกคะแนนของทีมนี้หรือไม่?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
            }).then(function (result) {
                if (result.isConfirmed) {
                    scoreForm.submit();
                }
            });
        });
    }

    // --- เมนูด้านข้าง (sidebar): ย่อ/ขยายบนจอใหญ่ (จำค่าไว้ด้วย localStorage), เปิด/ปิดแบบ overlay บนมือถือ ---
    var sidebarToggle = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('appSidebar');
    if (sidebarToggle && sidebar) {
        var root = document.documentElement;
        var isMobile = function () {
            return window.matchMedia('(max-width: 991.98px)').matches;
        };

        sidebarToggle.addEventListener('click', function () {
            if (isMobile()) {
                root.classList.toggle('sidebar-mobile-open');
            } else {
                root.classList.toggle('sidebar-collapsed');
                try {
                    localStorage.setItem('sidebarCollapsed', root.classList.contains('sidebar-collapsed') ? '1' : '0');
                } catch (e) {}
            }
        });

        document.addEventListener('click', function (e) {
            if (!isMobile() || !root.classList.contains('sidebar-mobile-open')) {
                return;
            }
            if (sidebar.contains(e.target) || sidebarToggle.contains(e.target)) {
                return;
            }
            root.classList.remove('sidebar-mobile-open');
        });

        sidebar.querySelectorAll('a.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (isMobile()) {
                    root.classList.remove('sidebar-mobile-open');
                }
            });
        });
    }
});
