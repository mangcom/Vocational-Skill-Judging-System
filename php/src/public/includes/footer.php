    </main>
<?php if ($user ?? current_user()): ?>
</div>
<?php endif; ?>
<footer class="text-center py-3">
    ระบบให้คะแนนกรรมการ &middot; ใช้งานภายในสำหรับกรรมการและผู้ดูแลระบบเท่านั้น
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
