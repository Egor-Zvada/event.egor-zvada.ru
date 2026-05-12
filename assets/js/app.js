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

(() => {
  const button = document.querySelector('[data-scroll-top]');
  button?.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();

(() => {
  const consent = document.querySelector('[data-privacy-consent]');
  const accept = document.querySelector('[data-privacy-accept]');
  const storageKey = 'event-privacy-consent-v1';
  if (!consent || !accept) return;

  let accepted = false;
  try {
    accepted = localStorage.getItem(storageKey) === '1';
  } catch {}

  if (!accepted) {
    consent.hidden = false;
  }

  accept.addEventListener('click', () => {
    try {
      localStorage.setItem(storageKey, '1');
    } catch {}
    consent.hidden = true;
  });
})();

(() => {
  const scanner = document.querySelector('[data-qr-scanner]');
  if (!scanner) return;

  const startButton = scanner.querySelector('[data-qr-start]');
  const video = scanner.querySelector('[data-qr-video]');
  const result = scanner.querySelector('[data-qr-result]');
  let detector = null;
  let stream = null;
  let scanning = false;
  let lastCode = '';

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));

  const setResult = (state, title, text = '', href = '') => {
    result.dataset.state = state;
    result.innerHTML = `
      <strong>${escapeHtml(title)}</strong>
      ${text ? `<span>${escapeHtml(text)}</span>` : ''}
      ${href ? `<a class="mini-button" href="${escapeHtml(href)}">Открыть билет</a>` : ''}
    `;
  };

  const ticketCodeFromValue = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.origin);
      return (url.searchParams.get('ticket') || url.searchParams.get('code') || '').trim().toUpperCase();
    } catch {
      return raw.trim().toUpperCase();
    }
  };

  const verifyTicket = async (rawValue) => {
    const code = ticketCodeFromValue(rawValue);
    if (!code || code === lastCode) return;
    lastCode = code;

    try {
      const response = await fetch(`/api.php?resource=ticket&code=${encodeURIComponent(code)}`, {
        headers: { Accept: 'application/json' },
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok || !payload.ticket) {
        setResult('denied', 'Проход запрещен', `Билет ${code} не найден в базе.`);
        return;
      }

      const ticket = payload.ticket;
      const days = Array.isArray(ticket.days) ? ticket.days.map((day) => day.event_date).join(', ') : '';
      const details = `${ticket.title}. Место ${ticket.seat_number}. ${ticket.venue}. ${days}`;
      if (ticket.status === 'cancelled') {
        setResult('denied', 'Проход запрещен', `Билет ${code} отменен. ${details}`, `/?ticket=${encodeURIComponent(code)}`);
      } else if (ticket.status === 'checked_in') {
        setResult('warning', 'Билет уже погашен', `${details}`, `/?ticket=${encodeURIComponent(code)}`);
      } else {
        setResult('allowed', 'Проход разрешен', `${details}`, `/?ticket=${encodeURIComponent(code)}`);
      }
    } catch {
      setResult('denied', 'Ошибка проверки', 'Не удалось обратиться к базе билетов.');
    }
  };

  const scanFrame = async () => {
    if (!scanning || !detector || !video.videoWidth) {
      if (scanning) requestAnimationFrame(scanFrame);
      return;
    }
    try {
      const codes = await detector.detect(video);
      if (codes.length) {
        await verifyTicket(codes[0].rawValue);
      }
    } catch {
      setResult('denied', 'Камера недоступна', 'Браузер не смог считать QR-код. Попробуйте открыть сайт по HTTPS или введите код вручную.');
      scanning = false;
    }
    if (scanning) requestAnimationFrame(scanFrame);
  };

  startButton?.addEventListener('click', async () => {
    if (!('BarcodeDetector' in window)) {
      setResult('denied', 'Сканер недоступен', 'Этот браузер не поддерживает чтение QR-кодов камерой. Введите код билета вручную.');
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      setResult('denied', 'Камера недоступна', 'Браузер не дал доступ к камере. На домене с HTTPS это должно работать.');
      return;
    }

    try {
      detector = new BarcodeDetector({ formats: ['qr_code'] });
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      video.srcObject = stream;
      video.hidden = false;
      await video.play();
      scanning = true;
      startButton.textContent = 'Камера включена';
      setResult('idle', 'Камера включена', 'Наведите камеру на QR-код билета.');
      requestAnimationFrame(scanFrame);
    } catch {
      setResult('denied', 'Камера недоступна', 'Разрешите доступ к камере или откройте сайт по HTTPS.');
    }
  });
})();
