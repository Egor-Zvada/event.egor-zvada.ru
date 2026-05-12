(() => {
  const seatMap = document.querySelector('[data-seat-map]');
  const seatInput = document.querySelector('[data-seat-input]');
  const dayPicker = document.querySelector('[data-day-picker]');
  if (!seatMap || !seatInput) return;

  const selectedDays = () => {
    if (!dayPicker) return [];
    return [...dayPicker.querySelectorAll('input[type="checkbox"]:checked')].map((input) => input.value);
  };

  const syncBusySeats = () => {
    const days = selectedDays();
    seatMap.querySelectorAll('[data-seat]').forEach((button) => {
      const busyDays = (button.dataset.busyDays || '').split(',').filter(Boolean);
      const busy = busyDays.some((day) => days.includes(day));
      button.disabled = busy;
      button.classList.toggle('is-busy', busy);
      if (busy && button.classList.contains('is-selected')) {
        button.classList.remove('is-selected');
        seatInput.value = '';
      }
    });
  };

  seatMap.addEventListener('click', (event) => {
    const button = event.target.closest('[data-seat]');
    if (!button || button.disabled) return;
    seatMap.querySelectorAll('.is-selected').forEach((item) => item.classList.remove('is-selected'));
    button.classList.add('is-selected');
    seatInput.value = button.dataset.seat || '';
  });

  dayPicker?.addEventListener('change', syncBusySeats);
  syncBusySeats();
})();

(() => {
  const root = document.documentElement;
  const toggle = document.querySelector('[data-theme-toggle]');
  const storageKey = 'egor-events-theme';
  const modeStorageKey = 'egor-events-theme-mode';
  const mediaQuery = window.matchMedia('(prefers-color-scheme: light)');

  const getStored = (key) => {
    try { return localStorage.getItem(key); } catch { return null; }
  };
  const setStored = (key, value) => {
    try { localStorage.setItem(key, value); } catch {}
  };
  const removeStored = (key) => {
    try { localStorage.removeItem(key); } catch {}
  };
  const systemTheme = () => mediaQuery.matches ? 'light' : 'dark';
  const initialTheme = () => {
    const saved = getStored(storageKey);
    return getStored(modeStorageKey) === 'manual' && (saved === 'light' || saved === 'dark') ? saved : systemTheme();
  };
  const applyTheme = (theme, persist = false) => {
    root.dataset.theme = theme;
    root.style.colorScheme = theme;
    if (persist) {
      setStored(storageKey, theme);
      setStored(modeStorageKey, 'manual');
    }
    const text = toggle?.querySelector('.theme-toggle__text');
    if (text) text.textContent = theme === 'light' ? 'dark' : 'light';
  };

  applyTheme(initialTheme());
  toggle?.addEventListener('click', () => applyTheme(root.dataset.theme === 'light' ? 'dark' : 'light', true));
  mediaQuery.addEventListener?.('change', () => {
    removeStored(modeStorageKey);
    applyTheme(systemTheme());
  });
})();

(() => {
  const entry = document.querySelector('[data-admin-entry]');
  if (!entry) return;
  let clicks = 0;
  let timer = 0;
  entry.addEventListener('click', () => {
    clicks += 1;
    window.clearTimeout(timer);
    timer = window.setTimeout(() => { clicks = 0; }, 1600);
    if (clicks >= 5) {
      window.location.href = '/admin/';
    }
  });
})();

(() => {
  const search = document.querySelector('[data-event-search]');
  const cards = [...document.querySelectorAll('[data-event-card]')];
  if (!search || !cards.length) return;

  search.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    cards.forEach((card) => {
      const haystack = card.dataset.eventTitle || '';
      card.hidden = query !== '' && !haystack.includes(query);
    });
  });
})();
