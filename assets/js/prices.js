(() => {
  const state = { cat: 'bhyt', q: '', all: false, per: 20, page: 1 };
  try { const saved = JSON.parse(localStorage.getItem('prices.view') || '{}'); if ([20, 50, 100].includes(saved.per)) state.per = saved.per; } catch (e) {}
  const body = document.getElementById('price-body');
  const perSel = document.getElementById('price-per');
  perSel.value = String(state.per);
  let reqSeq = 0, rows = [];
  // Thẻ thông tin khi rê chuột (một phần tử dùng chung cho mọi dòng)
  const card = document.createElement('div');
  card.className = 'popover bs-popover-top price-pop shadow';
  card.style.cssText = 'position:fixed;display:none;';
  card.innerHTML = '<h3 class="popover-header"></h3><div class="popover-body"></div>';
  document.body.appendChild(card);
  const hideCard = () => { card.style.display = 'none'; };

  const STATE_BADGE = {
    expired: '<span class="badge text-bg-secondary ms-1">Hết hiệu lực</span>',
    future: '<span class="badge text-bg-info ms-1">Sắp áp dụng</span>',
    active: '',
  };

  function detailHtml(r) {
    const row = (k, v) => v ? '<dt>' + k + '</dt><dd>' + App.esc(v) + '</dd>' : '';
    return '<dl>' + row('Quyết định', r.decision_name) + row('Ngày ban hành', r.decision_date) + row('Ngày áp dụng', r.effective_from) +
      row('Hết hiệu lực từ', r.effective_to ? 'sau ngày ' + r.effective_to : '') + row('Mã tương đương', r.equiv_code) + row('Mã kỹ thuật', r.tech_code) +
      row('Ghi chú', r.note) + '</dl>';
  }

  async function load() {
    const my = ++reqSeq;
    body.style.opacity = .5;
    const qs = new URLSearchParams({ cat: state.cat, q: state.q, all: state.all ? '1' : '0', per: state.per, page: state.page });
    try {
      const r = await App.api('api/prices.php?' + qs);
      if (my !== reqSeq) return; // bỏ kết quả cũ khi người dùng gõ nhanh
      hideCard(); rows = r.rows;
      if (!r.rows.length) {
        body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Không có dữ liệu phù hợp</td></tr>';
      } else {
        body.innerHTML = r.rows.map((x, i) =>
          '<tr class="price-row ' + (x.state === 'expired' ? 'expired' : '') + '" data-id="' + x.id + '" data-i="' + i + '">' +
          '<td>' + (r.from + i) + '</td><td class="small">' + App.esc(x.equiv_code || '') + '</td>' +
          '<td class="name">' + App.esc(x.name) + STATE_BADGE[x.state] + '</td><td class="small">' + App.esc(x.unit || '') + '</td>' +
          '<td class="num fw-semibold">' + App.money(x.price) + '</td><td class="small">' + App.esc(x.effective_from) + '</td></tr>').join('');
      }
      document.getElementById('price-summary').textContent = r.total ? ('Hiển thị ' + r.from + '–' + (r.from + r.rows.length - 1) + ' / ' + r.total + ' mục') : '';
      renderPages(r.page, r.pages);
    } catch (e) {
      body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">' + App.esc(e.message) + '</td></tr>';
    } finally { body.style.opacity = 1; }
  }

  function renderPages(page, pages) {
    state.page = page;
    const ul = document.getElementById('price-pages');
    if (pages <= 1) { ul.innerHTML = ''; return; }
    const items = [];
    const add = (p, label, disabled, active) => items.push('<li class="page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '') + '"><a class="page-link" href="#" data-p="' + p + '">' + label + '</a></li>');
    add(page - 1, '&laquo;', page === 1);
    let last = 0;
    for (let p = 1; p <= pages; p++) {
      if (p === 1 || p === pages || Math.abs(p - page) <= 2) {
        if (p - last > 1) items.push('<li class="page-item disabled"><span class="page-link">…</span></li>');
        add(p, p, false, p === page); last = p;
      }
    }
    add(page + 1, '&raquo;', page === pages);
    ul.innerHTML = items.join('');
  }

  document.getElementById('price-pages').addEventListener('click', ev => {
    const a = ev.target.closest('a[data-p]'); if (!a) return; ev.preventDefault();
    if (a.parentElement.classList.contains('disabled')) return;
    state.page = +a.dataset.p; load();
  });
  document.getElementById('price-tabs').addEventListener('click', ev => {
    const b = ev.target.closest('button[data-cat]'); if (!b) return;
    document.querySelectorAll('#price-tabs .nav-link').forEach(x => x.classList.toggle('active', x === b));
    state.cat = b.dataset.cat; state.page = 1; load();
  });
  document.getElementById('price-q').addEventListener('input', App.debounce(ev => { state.q = ev.target.value.trim(); state.page = 1; load(); }, 300));
  document.getElementById('price-all').addEventListener('change', ev => { state.all = ev.target.checked; state.page = 1; load(); });
  perSel.addEventListener('change', () => {
    state.per = +perSel.value; state.page = 1;
    try { localStorage.setItem('prices.view', JSON.stringify({ per: state.per })); } catch (e) {}
    load();
  });

  body.addEventListener('mouseover', ev => {
    const tr = ev.target.closest('tr.price-row'); if (!tr) return;
    const x = rows[+tr.dataset.i]; if (!x) return;
    card.querySelector('.popover-header').textContent = x.name;
    card.querySelector('.popover-body').innerHTML = detailHtml(x);
    card.style.display = 'block';
    const rc = tr.getBoundingClientRect(), h = card.offsetHeight, w = card.offsetWidth;
    const top = rc.top - h - 8 > 60 ? rc.top - h - 8 : rc.bottom + 8;
    card.style.top = top + 'px';
    card.style.left = Math.min(Math.max(8, rc.left + 120), window.innerWidth - w - 8) + 'px';
  });
  body.addEventListener('mouseleave', hideCard);
  window.addEventListener('scroll', hideCard, true);

  // Bấm vào dòng: xem chi tiết + lịch sử giá
  const modal = new bootstrap.Modal(document.getElementById('price-modal'));
  body.addEventListener('click', async ev => {
    const tr = ev.target.closest('tr.price-row'); if (!tr) return;
    hideCard();
    const mb = document.getElementById('price-modal-body');
    mb.innerHTML = '<div class="text-muted">Đang tải...</div>';
    modal.show();
    try {
      const r = await App.api('api/prices.php?history=' + tr.dataset.id + '&cat=' + state.cat);
      const it = r.item;
      mb.innerHTML = '<h6 class="mb-1">' + App.esc(it.name) + '</h6><div class="text-muted small mb-3">' + App.esc(it.unit || '') + '</div>' +
        '<div class="popover position-static d-block mw-100 border-0 shadow-none"><div class="popover-body p-0">' + detailHtml(it) + '</div></div>' +
        '<h6 class="mt-4">Lịch sử giá</h6><table class="table table-sm"><thead><tr><th>Áp dụng từ</th><th>Đến</th><th class="num">Đơn giá</th><th>Quyết định</th></tr></thead><tbody>' +
        r.history.map(h => '<tr class="' + (h.id === it.id ? 'table-primary' : '') + '"><td>' + h.effective_from + '</td><td>' + (h.effective_to || '<span class="text-success">nay</span>') +
          '</td><td class="num">' + App.money(h.price) + '</td><td class="small">' + App.esc(h.decision_name || '') + (h.decision_date ? ' (' + h.decision_date + ')' : '') + '</td></tr>').join('') +
        '</tbody></table>';
    } catch (e) { mb.innerHTML = '<div class="text-danger">' + App.esc(e.message) + '</div>'; }
  });

  load();
})();
