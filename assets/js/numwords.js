// Đọc số tiền thành chữ tiếng Việt (giống inc/number_words.php)
window.vnNumberWords = function (n) {
  n = Math.round(Number(n) || 0);
  if (n === 0) return 'Không đồng';
  const d = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
  const units = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];
  const triple = (g, full) => {
    const tr = Math.floor(g / 100), ch = Math.floor((g % 100) / 10), dv = g % 10, out = [];
    if (tr > 0 || full) out.push(d[tr] + ' trăm');
    if (ch === 0) { if (dv > 0 && (tr > 0 || full)) out.push('linh'); }
    else if (ch === 1) out.push('mười'); else out.push(d[ch] + ' mươi');
    if (dv > 0) out.push(dv === 1 && ch >= 2 ? 'mốt' : dv === 5 && ch >= 1 ? 'lăm' : dv === 4 && ch >= 2 ? 'tư' : d[dv]);
    return out.join(' ');
  };
  const groups = [];
  let x = Math.abs(n);
  while (x > 0) { groups.push(x % 1000); x = Math.floor(x / 1000); }
  const parts = [];
  for (let i = groups.length - 1; i >= 0; i--) {
    if (groups[i] === 0) continue;
    parts.push(triple(groups[i], i < groups.length - 1) + units[i]);
  }
  const s = parts.join(' ').trim();
  return s.charAt(0).toUpperCase() + s.slice(1) + ' đồng';
};
