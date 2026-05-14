// Modal state
let modalFilters = { langs: [], modes: [], locations: [], rating: 0 };
let modalRatingBtn = null;

function openSearch() {
  const modal = document.getElementById('searchModal');
  if (!modal) return;
  modal.style.display = 'block';
  setTimeout(() => {
    const input = document.getElementById('modalTutorSearchInput');
    if (input) input.focus();
  }, 100);
}

function closeSearch() {
  const modal = document.getElementById('searchModal');
  if (!modal) return;
  modal.style.display = 'none';
}

function toggleSearchFilters() {
  const filters = document.getElementById('searchFilters');
  if (!filters) return;
  filters.style.display = filters.style.display === 'block' ? 'none' : 'block';
}

function toggleModalChip(el, type) {
  const val = el.dataset.value;
  const isActive = el.classList.contains('chip-active');
  if (isActive) {
    el.classList.remove('chip-active');
    el.style.background = 'white'; el.style.color = '#7A5570'; el.style.borderColor = 'rgba(46,42,59,.12)';
    if (type === 'lang')     modalFilters.langs     = modalFilters.langs.filter(v => v !== val);
    if (type === 'mode')     modalFilters.modes     = modalFilters.modes.filter(v => v !== val);
    if (type === 'location') modalFilters.locations = modalFilters.locations.filter(v => v !== val);
  } else {
    el.classList.add('chip-active');
    el.style.background = 'linear-gradient(135deg,#E75A9B,#F28AB2)';
    el.style.color = 'white'; el.style.borderColor = '#E75A9B';
    if (type === 'lang')     modalFilters.langs.push(val);
    if (type === 'mode')     modalFilters.modes.push(val);
    if (type === 'location') modalFilters.locations.push(val);
  }
  updateModalFilterDot();
}

function checkModalLocationFilter() {
  const f2f = document.getElementById('modalF2fChip');
  const box = document.getElementById('modalLocationBox');
  if (!f2f || !box) return;
  const active = f2f.classList.contains('chip-active');
  box.style.display = active ? 'block' : 'none';
  if (!active) {
    modalFilters.locations = [];
    document.querySelectorAll('#modalLocationChips .modal-filter-chip').forEach(b => {
      b.classList.remove('chip-active');
      b.style.background = 'white'; b.style.color = '#7A5570'; b.style.borderColor = 'rgba(46,42,59,.12)';
    });
  }
}

function setModalRating(el, val) {
  if (modalRatingBtn === el) {
    el.classList.remove('chip-active');
    el.style.background = 'white'; el.style.color = '#7A5570'; el.style.borderColor = 'rgba(46,42,59,.12)';
    modalFilters.rating = 0; modalRatingBtn = null;
  } else {
    if (modalRatingBtn) {
      modalRatingBtn.classList.remove('chip-active');
      modalRatingBtn.style.background = 'white'; modalRatingBtn.style.color = '#7A5570'; modalRatingBtn.style.borderColor = 'rgba(46,42,59,.12)';
    }
    el.classList.add('chip-active');
    el.style.background = 'linear-gradient(135deg,#E75A9B,#F28AB2)';
    el.style.color = 'white'; el.style.borderColor = '#E75A9B';
    modalFilters.rating = val; modalRatingBtn = el;
  }
  updateModalFilterDot(); filterTutors();
}

function updateModalFilterDot() {
  const from = parseFloat(document.getElementById('priceFrom')?.value) || 0;
  const to   = parseFloat(document.getElementById('priceTo')?.value) || 100;
  const has  = modalFilters.langs.length > 0 || modalFilters.modes.length > 0
    || modalFilters.locations.length > 0 || modalFilters.rating > 0
    || from > 0 || to < 100;
  const dot = document.getElementById('filterDot');
  if (dot) dot.style.display = has ? 'block' : 'none';
}

function clearSearchFilters() {
  modalFilters = { langs: [], modes: [], locations: [], rating: 0 };
  const pf = document.getElementById('priceFrom');
  const pt = document.getElementById('priceTo');
  if (pf) pf.value = 0;
  if (pt) pt.value = 100;
  const lb = document.getElementById('modalLocationBox');
  if (lb) lb.style.display = 'none';
  document.querySelectorAll('.modal-filter-chip').forEach(b => {
    b.classList.remove('chip-active');
    b.style.background = 'white'; b.style.color = '#7A5570'; b.style.borderColor = 'rgba(46,42,59,.12)';
  });
  if (modalRatingBtn) { modalRatingBtn = null; }
  updateModalFilterDot(); filterTutors();
}

function filterTutors() {
  const input = document.getElementById('modalTutorSearchInput');
  if (!input) return;
  const val       = input.value.toLowerCase().trim();
  const fromPrice = parseFloat(document.getElementById('priceFrom')?.value) || 0;
  const toPrice   = parseFloat(document.getElementById('priceTo')?.value) || 100;
  const items     = document.querySelectorAll('.search-tutor-item');
  let count = 0;

  items.forEach(item => {
    const langs    = (item.dataset.lang || '').split(',').map(l => l.trim().toLowerCase()).filter(Boolean);
    const modes    = (item.dataset.mode || '').split(',').map(m => m.trim().toLowerCase()).filter(Boolean);
    const location = (item.dataset.location || '').toLowerCase().trim();
    const rate     = parseFloat(item.dataset.rate || 0);
    const rating   = parseFloat(item.dataset.rating || 0);

    const searchMatch   = val === '' || langs.some(l => l.includes(val));
    const priceMatch    = rate >= fromPrice && rate <= toPrice;
    const langMatch     = modalFilters.langs.length === 0 || modalFilters.langs.some(fl => langs.some(l => l.includes(fl)));
    const modeMatch     = modalFilters.modes.length === 0 || modalFilters.modes.some(fm => modes.some(m => m.includes(fm)));
    const locationMatch = modalFilters.locations.length === 0 || modalFilters.locations.some(loc => location.includes(loc));
    const ratingMatch   = modalFilters.rating === 0 || rating >= modalFilters.rating;

    const show = searchMatch && priceMatch && langMatch && modeMatch && locationMatch && ratingMatch;
    item.style.display = show ? 'flex' : 'none';
    if (show) count++;
  });

  const rc = document.getElementById('resultCount');
  if (rc) rc.textContent = count + ' tutor' + (count !== 1 ? 's' : '') + ' found';
}

// Close modal on backdrop click
document.addEventListener('click', function(e) {
  const modal = document.getElementById('searchModal');
  if (!modal) return;
  if (e.target === modal) closeSearch();

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 1800);
  }
});