(() => {
  const form = document.getElementById('voucher-form');
  if (!form) return;
  const km = document.getElementById('v-km');
  const price = document.getElementById('v-price');
  const { lpk, rounding } = window.VOUCHER;

  function recalc() {
    const k = parseFloat(km.value) || 0;
    const p = Number((price.value || '').replace(/\D/g, '')) || 0;
    const amount = Math.round((k * lpk * p) / rounding) * rounding;
    document.getElementById('v-total').textContent = App.money(amount) + ' đ';
    document.getElementById('v-words').textContent = vnNumberWords(amount);
    document.getElementById('v-formula').textContent = (k || 'km') + ' × ' + String(lpk).replace('.', ',') + ' lít × ' + (p ? App.money(p) : 'giá xăng');
  }

  // Chọn "Khác" thì hiện ô nhập tay; chọn cơ sở tiếp nhận thì tự điền khoảng cách
  form.querySelectorAll('.fac-select').forEach(sel => {
    const other = form.querySelector('[name="' + sel.dataset.other + '"]');
    const sync = () => {
      const isOther = sel.value === '0';
      other.hidden = !isOther; other.required = isOther;
    };
    sel.addEventListener('change', () => {
      sync();
      if (sel.id === 'to-select') {
        const opt = sel.selectedOptions[0];
        if (opt && opt.dataset.km) { km.value = opt.dataset.km; recalc(); }
      }
    });
    sync();
  });

  km.addEventListener('input', recalc);
  price.addEventListener('input', recalc);
  recalc();

  form.addEventListener('submit', (ev) => {
    const from = form.querySelector('[name=from_id]').value, to = form.querySelector('[name=to_id]').value;
    if (from === '' || to === '') { ev.preventDefault(); App.toast('Chọn cơ sở chuyển đi và cơ sở tiếp nhận', 'warning'); return; }
    if (from !== '0' && from === to) { ev.preventDefault(); App.toast('Cơ sở chuyển đi và tiếp nhận phải khác nhau', 'warning'); return; }
    const total = document.getElementById('v-total').textContent;
    if (!confirm('Lưu phiếu chi số tiền ' + total + ' và mở trang in?')) ev.preventDefault();
  });

  // Hủy phiếu (điều hành)
  document.querySelectorAll('[data-cancel]').forEach(btn => btn.addEventListener('click', () => {
    const reason = prompt('Lý do hủy phiếu số ' + btn.dataset.no + ':');
    if (!reason) return;
    const f = document.getElementById('cancel-form');
    f.elements['id'].value = btn.dataset.cancel; f.elements['cancel_reason'].value = reason; f.submit();
  }));
})();
