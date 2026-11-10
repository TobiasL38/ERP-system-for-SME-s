        </div>
        <footer class="page-footer"><span>SME ERP</span><span>All monetary values in KES</span></footer>
    </main>
</div>
<script>
document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm || 'Are you sure?')) event.preventDefault();
    });
});
</script>
</body>
</html>
