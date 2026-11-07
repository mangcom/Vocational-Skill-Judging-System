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
});
