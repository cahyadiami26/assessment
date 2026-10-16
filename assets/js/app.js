/* E-Assessment LJU - Common JS */
document.addEventListener('DOMContentLoaded', function () {
  // Tab navigation (data-tab target)
  document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = btn.closest('.tabs');
      var wrap  = group.parentElement;
      group.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      wrap.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      var pane = wrap.querySelector('#' + btn.dataset.tab);
      if (pane) pane.classList.add('active');
    });
  });

  // Auto submit form on filter change
  document.querySelectorAll('select[data-auto-submit]').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });

  // Konfirmasi aksi berbahaya
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!confirm(el.dataset.confirm)) ev.preventDefault();
    });
  });
});

/* Simple Rich Text Editor (contenteditable) untuk esai */
function initRTE(id) {
  var area = document.getElementById('rte-' + id);
  var hidden = document.getElementById('rte-hidden-' + id);
  if (!area || !hidden) return;
  area.addEventListener('input', function () { hidden.value = area.innerHTML; });
  document.querySelectorAll('#rte-toolbar-' + id + ' button').forEach(function (b) {
    b.addEventListener('mousedown', function (e) {
      e.preventDefault();
      document.execCommand(b.dataset.cmd, false, b.dataset.val || null);
      area.focus();
      hidden.value = area.innerHTML;
    });
  });
}
