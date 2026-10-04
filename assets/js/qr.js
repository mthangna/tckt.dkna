(() => {
  const form = document.getElementById('qr-form');
  const box = document.getElementById('qr-box');
  const info = document.getElementById('qr-info');
  const statusEl = document.getElementById('qr-status');
  const badge = document.getElementById('qr-status-badge');
  let current = null, pollTimer = null, pollUntil = 0;

  const STATUS = {
    pending: ['warning', 'Đang chờ người bệnh chuyển khoản...', 'bi-hourglass-split'],
    paid: ['success', 'Đã nhận tiền', 'bi-check-circle-fill'],
    cancelled: ['secondary', 'Đã hủy', 'bi-x-circle'],
  };

  function drawQR(payload) {
    const qr = qrcode(0, 'M');
    qr.addData(payload);
    qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
  }

  function showStatus(status, extra) {
    const [cls, text, icon] = STATUS[status] || STATUS.pending;
    statusEl.className = 'qr-status alert mb-2 alert-' + cls;
    statusEl.innerHTML = '<i class="bi ' + icon + '"></i> ' + App.esc(text) + (extra ? '<div class="small fw-normal mt-1">' + App.esc(extra) + '</div>' : '');
    badge.innerHTML = '<span class="badge text-bg-' + cls + '">' + App.esc(text.replace('...', '')) + '</span>';
    const pending = status === 'pending';
    document.querySelectorAll('#btn-cancel,#btn-confirm,#btn-demo-pay').forEach(b => b.disabled = !pending);
    if (!pending) stopPolling();
  }

  function render(req) {
    current = req;
    drawQR(req.payload);
    info.hidden = false;
    const set = (f, v) => { const el = info.querySelector('[data-f="' + f + '"]'); if (el) el.textContent = v || '—'; };
    set('bank', req.bank.name); set('account_no', req.bank.account_no); set('account_name', req.bank.account_name);
    set('treatment_code', req.treatment_code); set('patient_name', req.patient_name); set('content', req.content);
    set('amount', App.money(req.amount) + ' đ');
    showStatus(req.status, req.status === 'paid' ? ('Lúc ' + req.paid_at + (req.confirm_note ? ' · ' + req.confirm_note : '')) : '');
    if (req.status === 'pending') startPolling();
  }

  function startPolling() {
    stopPolling();
    pollUntil = Date.now() + 15 * 60 * 1000; // dừng sau 15 phút
    pollTimer = setInterval(async () => {
      if (!current || Date.now() > pollUntil) { stopPolling(); return; }
      try {
        const r = await App.api('api/qr.php?action=status&id=' + current.id);
        if (r.status !== 'pending') {
          current.status = r.status;
          showStatus(r.status, r.status === 'paid' ? ('Lúc ' + r.paid_at + (r.confirm_note ? ' · ' + r.confirm_note : '')) : '');
          if (r.status === 'paid') App.toast('Đã nhận tiền cho mã điều trị ' + current.treatment_code);
          loadList();
        }
      } catch (e) { /* thử lại ở lượt sau */ }
    }, 3000);
  }
  function stopPolling() { if (pollTimer) clearInterval(pollTimer); pollTimer = null; }

  async function loadList() {
    const tb = document.getElementById('qr-list');
    try {
      const r = await App.api('api/qr.php?action=list');
      if (!r.rows.length) { tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Chưa có yêu cầu nào hôm nay</td></tr>'; return; }
      tb.innerHTML = r.rows.map(x => {
        const [cls, text] = STATUS[x.status];
        return '<tr role="button" data-id="' + x.id + '"><td>' + x.time + '</td><td class="fw-semibold">' + App.esc(x.treatment_code) + '</td><td>' + App.esc(x.patient_name || '') +
          '</td><td class="num">' + App.money(x.amount) + '</td><td><span class="badge text-bg-' + cls + '">' + App.esc(text.replace('...', '')) + (x.paid_at ? ' ' + x.paid_at : '') +
          '</span></td><td class="small text-muted">' + App.esc(x.creator) + '</td></tr>';
      }).join('');
    } catch (e) { tb.innerHTML = '<tr><td colspan="6" class="text-danger text-center py-3">' + App.esc(e.message) + '</td></tr>'; }
  }

  form?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = form.querySelector('button');
    btn.disabled = true;
    try {
      const fd = new FormData(form);
      const r = await App.api('api/qr.php?action=create', { body: Object.fromEntries(fd.entries()) });
      render(r.request);
      loadList();
    } catch (e) { App.toast(e.message, 'danger'); }
    finally { btn.disabled = false; }
  });

  // Xem trước nội dung chuyển khoản: mã điều trị + họ tên không dấu (giống quy tắc ở máy chủ)
  const transferText = (v) => v.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[đĐ]/g, 'D')
    .toUpperCase().replace(/[^A-Z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
  function previewContent() {
    const el = document.getElementById('qr-content-preview');
    if (!el || !form) return;
    const code = form.elements['treatment_code'].value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    let s = transferText(code + ' ' + form.elements['patient_name'].value);
    if (s.length > 70) { s = s.slice(0, 71); s = s.slice(0, s.lastIndexOf(' ')).trim(); }
    el.textContent = s || 'mã điều trị + họ tên không dấu';
  }
  form?.addEventListener('input', previewContent);
  const accSel = document.getElementById('qr-account');
  accSel?.addEventListener('change', () => {
    const o = accSel.selectedOptions[0];
    const card = document.getElementById('acc-card');
    card.querySelector('[data-acc=bank]').textContent = o.dataset.bank;
    card.querySelector('[data-acc=name]').textContent = o.dataset.name;
    card.querySelector('[data-acc=no]').textContent = o.dataset.no;
  });

  document.getElementById('qr-list').addEventListener('click', async (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    try { const r = await App.api('api/qr.php?action=get&id=' + tr.dataset.id); render(r.request); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    catch (e) { App.toast(e.message, 'danger'); }
  });

  document.getElementById('btn-cancel')?.addEventListener('click', async () => {
    if (!current || !confirm('Hủy yêu cầu thanh toán này?')) return;
    try { await App.api('api/qr.php?action=cancel', { body: { id: current.id } }); showStatus('cancelled'); loadList(); }
    catch (e) { App.toast(e.message, 'danger'); }
  });
  document.getElementById('btn-confirm')?.addEventListener('click', async () => {
    if (!current) return;
    const note = prompt('Căn cứ xác nhận đã nhận tiền (vd: đã kiểm tra trên iBank, số tham chiếu FT...):');
    if (note === null) return;
    try { await App.api('api/qr.php?action=confirm', { body: { id: current.id, note } }); const r = await App.api('api/qr.php?action=get&id=' + current.id); render(r.request); loadList(); }
    catch (e) { App.toast(e.message, 'danger'); }
  });
  document.getElementById('btn-demo-pay')?.addEventListener('click', async () => {
    if (!current) return;
    try {
      const r = await App.api('api/qr.php?action=demo_pay', { body: { id: current.id } });
      App.toast(r.matched ? 'Đã gửi giao dịch giả lập, chờ hệ thống khớp...' : 'Giao dịch giả lập không khớp yêu cầu nào', r.matched ? 'info' : 'warning');
    } catch (e) { App.toast(e.message, 'danger'); }
  });
  document.getElementById('btn-reload').addEventListener('click', loadList);
  loadList();
})();
