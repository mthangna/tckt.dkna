// Tiện ích dùng chung
window.App = {
  csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
  base: (document.querySelector('link[href*="assets/css/app.css"]')?.getAttribute('href') || '').split('assets/css/app.css')[0],
  url(path) { return this.base + path.replace(/^\//, ''); },
  async api(path, opts = {}) {
    const init = { method: opts.method || 'GET', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (opts.body) {
      init.method = opts.method || 'POST';
      init.headers['X-CSRF-Token'] = this.csrf;
      if (opts.body instanceof FormData) { init.body = opts.body; }
      else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    }
    let res;
    try { res = await fetch(this.url(path), init); }
    catch (e) { throw new Error('Không kết nối được máy chủ'); }
    let data = null;
    try { data = await res.json(); } catch (e) { /* ignore */ }
    if (!res.ok || !data || data.ok === false) throw new Error((data && data.error) || ('Lỗi máy chủ (' + res.status + ')'));
    return data;
  },
  money(n) { return Number(n || 0).toLocaleString('vi-VN'); },
  esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); },
  toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + type + ' border-0';
    el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    el.querySelector('.toast-body').textContent = msg;
    document.getElementById('toasts').appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3500 }); t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  },
  debounce(fn, ms = 300) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; },
};
// Ô nhập tiền: tự thêm dấu chấm phân cách
document.addEventListener('input', (ev) => {
  const el = ev.target;
  if (el.matches && el.matches('input[data-money]')) {
    const digits = el.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    el.value = digits ? Number(digits).toLocaleString('vi-VN') : '';
  }
});
// Xác nhận trước khi gửi form nguy hiểm
document.addEventListener('submit', (ev) => {
  const msg = ev.target.getAttribute('data-confirm');
  if (msg && !confirm(msg)) ev.preventDefault();
});
