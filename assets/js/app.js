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
  root.dataset.theme = 'light';
  root.style.colorScheme = 'light';
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
