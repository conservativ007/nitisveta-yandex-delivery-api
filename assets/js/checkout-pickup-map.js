(function ($, config) {
	if (!config) return;

	const state = {
		points: [],
		selectedPoint: null,
		map: null,
		clusterer: null,
		mapPromise: null,
		modalBound: false,
		isLoading: false,
	};

	const selectors = {
		city: '#billing_city',
		country: '#billing_country',
		shipping: '#shipping_method',
		methodInput: 'input.shipping_method',
	};

	function getYandexMethodInput() {
		const methodIds = config.shippingMethodIds || [config.shippingMethodId || 'nitisveta_yandex_pickup', 'flat_rate:10'];
		let input = null;

		methodIds.some(function (methodId) {
			input = document.querySelector(`${selectors.shipping} input[value="${escapeSelector(methodId)}"]`);
			return !!input;
		});

		if (!input) {
			input = document.querySelector(`${selectors.shipping} input[id*="nitisveta_yandex_pickup"], ${selectors.shipping} input[id*="flat_rate-10"]`);
		}

		return input;
	}

	function markYandexShippingItem() {
		const input = getYandexMethodInput();
		const item = input?.closest('li');
		if (!item) return;

		item.classList.add('nitisveta-yandex-pickup-shipping-method');
		item.dataset.shippingProvider = 'yandex-pickup';
	}

	function isYandexSelected() {
		const input = getYandexMethodInput();
		return !!input && (input.checked || input.type === 'hidden');
	}

	function getContainer() {
		let container = document.querySelector('.nitisveta-yandex-pickup');
		const input = getYandexMethodInput();
		const li = input ? input.closest('li') : null;
		const mount = document.getElementById('nitisveta-yandex-pickup-mount');

		markYandexShippingItem();

		if (!li && !mount) return null;

		if (!container) {
			container = document.createElement('div');
			container.className = 'nitisveta-yandex-pickup';
			container.innerHTML = `
				<input type="hidden" name="yandex_delivery_pickup_point_id" id="yandex_delivery_pickup_point_id">
				<input type="hidden" name="yandex_delivery_pickup_point_name" id="yandex_delivery_pickup_point_name">
				<input type="hidden" name="yandex_delivery_pickup_point_address" id="yandex_delivery_pickup_point_address">
				<input type="hidden" name="yandex_delivery_pickup_point_lat" id="yandex_delivery_pickup_point_lat">
				<input type="hidden" name="yandex_delivery_pickup_point_lon" id="yandex_delivery_pickup_point_lon">
				<div class="nitisveta-yandex-pickup__header">
					<strong>${escapeHtml(config.labels.title)}</strong>
					<button type="button" class="nitisveta-yandex-pickup__open">${escapeHtml(config.labels.choose)}</button>
				</div>
				<div class="nitisveta-yandex-pickup__selected" hidden>
					<span class="nitisveta-yandex-pickup__selected-title">${escapeHtml(config.labels.selected)}</span>
					<span class="nitisveta-yandex-pickup__selected-address"></span>
				</div>
			`;
			(li || mount).appendChild(container);
			bindContainer(container);
		}

		if (li && container.parentElement !== li) {
			li.appendChild(container);
		} else if (!li && mount && container.parentElement !== mount) {
			mount.appendChild(container);
		}

		renderSelectedPoint(state.selectedPoint || readSelectedPointFromFields(), container);

		return container;
	}

	function bindContainer(container) {
		container.querySelector('.nitisveta-yandex-pickup__open').addEventListener('click', function () {
			openModal();
		});
	}

	function getModal() {
		let modal = document.querySelector('.nitisveta-yandex-pickup-modal');
		if (modal) return modal;

		modal = document.createElement('div');
		modal.className = 'nitisveta-yandex-pickup-modal';
		modal.hidden = true;
		modal.innerHTML = `
			<div class="nitisveta-yandex-pickup-modal__overlay" data-yandex-pickup-close></div>
			<div class="nitisveta-yandex-pickup-modal__dialog" role="dialog" aria-modal="true" aria-label="${escapeAttr(config.labels.title)}">
				<div class="nitisveta-yandex-pickup-modal__header">
					<strong>${escapeHtml(config.labels.title)}</strong>
					<button type="button" class="nitisveta-yandex-pickup-modal__close" aria-label="Закрыть" data-yandex-pickup-close>×</button>
				</div>
				<div class="nitisveta-yandex-pickup-modal__map nitisveta-yandex-pickup__map"></div>
				<div class="nitisveta-yandex-pickup-modal__bottom">
					<input class="nitisveta-yandex-pickup__search" type="search" placeholder="${escapeHtml(config.labels.search)}">
					<div class="nitisveta-yandex-pickup__status"></div>
					<ul class="nitisveta-yandex-pickup__list"></ul>
				</div>
			</div>
		`;
		document.body.appendChild(modal);

		modal.querySelectorAll('[data-yandex-pickup-close]').forEach(function (button) {
			button.addEventListener('click', closeModal);
		});

		modal.querySelector('.nitisveta-yandex-pickup__search').addEventListener('input', function () {
			renderList(this.value.trim());
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !modal.hidden) {
				closeModal();
			}
		});

		return modal;
	}

	function openModal() {
		const modal = getModal();
		modal.hidden = false;
		document.documentElement.classList.add('nitisveta-yandex-pickup-modal-open');

		const search = modal.querySelector('.nitisveta-yandex-pickup__search');
		if (search) search.focus({ preventScroll: true });

		if (!state.points.length && !state.isLoading) {
			loadPoints();
			return;
		}

		renderList(search?.value || '');
		renderMap();
	}

	function closeModal() {
		const modal = document.querySelector('.nitisveta-yandex-pickup-modal');
		if (!modal) return;
		modal.hidden = true;
		document.documentElement.classList.remove('nitisveta-yandex-pickup-modal-open');
	}

	function toggleContainer() {
		markYandexShippingItem();

		const container = getContainer();
		if (!container) return;

		container.hidden = !isYandexSelected();

		if (container.hidden) {
			closeModal();
		}
	}

	async function loadPoints() {
		getContainer();
		getModal();
		state.isLoading = true;

		setStatus(config.labels.loading);

		const city = getCheckoutCity();
		const country = document.querySelector(selectors.country)?.value || 'RU';

		if (!city || city === 'город') {
			state.points = [];
			setStatus('Сначала выберите город');
			renderList('');
			renderMap();
			return;
		}

		const url = new URL(config.restUrl, window.location.origin);
		url.searchParams.set('city', city);
		url.searchParams.set('country', country);

		try {
			const response = await fetch(url.toString(), {
				headers: {
					'X-WP-Nonce': config.restNonce || '',
				},
			});

			const responseText = await response.text();
			if (!response.ok) throw new Error(`HTTP ${response.status}: ${responseText.slice(0, 500)}`);

			let payload;
			try {
				payload = JSON.parse(responseText);
			} catch (parseError) {
				throw new Error(`Invalid JSON from pickup points endpoint: ${responseText.slice(0, 500)}`);
			}

			state.points = Array.isArray(payload.points) ? payload.points : [];

			if (!state.points.length) {
				setStatus(config.labels.empty);
				renderList('');
				renderMap();
				return;
			}

			setStatus('');
			renderList('');
			await renderMap();
		} catch (error) {
			console.error(error);
			state.points = [];
			setStatus(config.labels.error);
			renderList('');
		} finally {
			state.isLoading = false;
		}
	}

	function getCheckoutCity() {
		const citySelect = document.querySelector(selectors.city);
		const candidates = [];

		if (citySelect) {
			candidates.push(citySelect.value);
			candidates.push(citySelect.selectedOptions?.[0]?.value);
			candidates.push(citySelect.selectedOptions?.[0]?.textContent);
		}

		if (window.jQuery && jQuery.fn.select2) {
			const data = jQuery(selectors.city).select2('data');
			if (Array.isArray(data) && data[0]) {
				candidates.push(data[0].id);
				candidates.push(data[0].text);
			}
		}

		const renderedCity = document.querySelector('#select2-billing_city-container');
		if (renderedCity) {
			candidates.push(renderedCity.getAttribute('title'));
			candidates.push(renderedCity.textContent);
		}

		return candidates
			.map(function (value) {
				return String(value || '').trim();
			})
			.find(function (value) {
				return value && !['город', 'выберите город'].includes(value.toLowerCase());
			}) || '';
	}

	async function renderMap() {
		const modal = getModal();
		const mapNode = modal?.querySelector('.nitisveta-yandex-pickup__map');
		if (!mapNode) return;

		if (!state.points.length) {
			mapNode.hidden = true;
			return;
		}

		mapNode.hidden = false;

		try {
			await loadYandexMaps();
		} catch (error) {
			mapNode.hidden = true;
			return;
		}

		const first = state.points[0];
		if (!state.map) {
			state.map = new ymaps.Map(mapNode, {
				center: [first.lat, first.lon],
				zoom: 11,
				controls: ['zoomControl'],
			});
		} else {
			state.map.container.fitToViewport();
		}

		state.map.geoObjects.removeAll();
		const collection = new ymaps.GeoObjectCollection();

		state.points.forEach(function (point) {
			const placemark = new ymaps.Placemark([point.lat, point.lon], {
				balloonContentHeader: escapeHtml(point.name),
				balloonContentBody: escapeHtml(point.address),
				hintContent: escapeHtml(point.address),
			});

			placemark.events.add('click', function () {
				selectPoint(point);
			});

			collection.add(placemark);
		});

		state.map.geoObjects.add(collection);

		if (state.points.length > 1) {
			state.map.setBounds(collection.getBounds(), {
				checkZoomRange: true,
				zoomMargin: 32,
			});
		} else {
			state.map.setCenter([first.lat, first.lon], 14);
		}
	}

	function renderList(query) {
		const modal = getModal();
		const list = modal?.querySelector('.nitisveta-yandex-pickup__list');
		if (!list) return;

		const normalizedQuery = query.toLowerCase();
		const points = state.points.filter(function (point) {
			if (!normalizedQuery) return true;
			return `${point.name} ${point.address}`.toLowerCase().includes(normalizedQuery);
		});

		list.innerHTML = points.slice(0, 30).map(function (point) {
			const selected = state.selectedPoint && state.selectedPoint.id === point.id;
			return `
				<li>
					<button type="button" class="${selected ? 'is-selected' : ''}" data-point-id="${escapeAttr(point.id)}">
						<span>${escapeHtml(point.name)}</span>
						<small>${escapeHtml(point.address)}</small>
						${point.schedule ? `<em>${escapeHtml(point.schedule)}</em>` : ''}
					</button>
				</li>
			`;
		}).join('');

		list.querySelectorAll('button[data-point-id]').forEach(function (button) {
			button.addEventListener('click', function () {
				const point = state.points.find(function (item) {
					return item.id === button.dataset.pointId;
				});

				if (point) selectPoint(point);
			});
		});
	}

	async function selectPoint(point) {
		state.selectedPoint = point;
		writeSelectedPoint(point);
		renderSelectedPoint(point);
		renderList(document.querySelector('.nitisveta-yandex-pickup__search')?.value || '');

		if (state.map) {
			state.map.setCenter([point.lat, point.lon], 15, { duration: 200 });
		}

		await storeSelectedPoint(point);
		closeModal();
		jQuery(document.body).trigger('update_checkout');
	}

	function writeSelectedPoint(point) {
		const fields = {
			yandex_delivery_pickup_point_id: point?.id || '',
			yandex_delivery_pickup_point_name: point?.name || '',
			yandex_delivery_pickup_point_address: point?.address || '',
			yandex_delivery_pickup_point_lat: point?.lat || '',
			yandex_delivery_pickup_point_lon: point?.lon || '',
		};

		Object.keys(fields).forEach(function (id) {
			const input = document.getElementById(id);
			if (input) input.value = fields[id];
		});
	}

	async function storeSelectedPoint(point) {
		if (!config.selectedPointRestUrl) return;

		try {
			await fetch(config.selectedPointRestUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.restNonce || '',
				},
				body: JSON.stringify({
					id: point?.id || '',
					name: point?.name || '',
					address: point?.address || '',
					lat: point?.lat || '',
					lon: point?.lon || '',
				}),
			});
		} catch (error) {
			console.error(error);
		}
	}

	function renderSelectedPoint(point, existingContainer) {
		const container = existingContainer || getContainer();
		const selected = container?.querySelector('.nitisveta-yandex-pickup__selected');
		if (!selected) return;

		if (!point) {
			selected.hidden = true;
			const address = selected.querySelector('.nitisveta-yandex-pickup__selected-address');
			if (address) address.textContent = '';
			const openButton = container.querySelector('.nitisveta-yandex-pickup__open');
			if (openButton) {
				openButton.textContent = config.labels.choose;
			}
			return;
		}

		selected.hidden = false;
		const address = selected.querySelector('.nitisveta-yandex-pickup__selected-address');
		if (address) {
			address.textContent = point.address || point.name || point.id || '';
		}

		const openButton = container.querySelector('.nitisveta-yandex-pickup__open');
		if (openButton) {
			openButton.textContent = config.labels.change;
		}
	}

	function readSelectedPointFromFields() {
		const id = document.getElementById('yandex_delivery_pickup_point_id')?.value || '';
		if (!id) return null;

		return {
			id,
			name: document.getElementById('yandex_delivery_pickup_point_name')?.value || '',
			address: document.getElementById('yandex_delivery_pickup_point_address')?.value || '',
			lat: document.getElementById('yandex_delivery_pickup_point_lat')?.value || '',
			lon: document.getElementById('yandex_delivery_pickup_point_lon')?.value || '',
		};
	}

	function setStatus(text) {
		const status = getModal()?.querySelector('.nitisveta-yandex-pickup__status');
		if (status) status.textContent = text || '';
	}

	function validateBeforeSubmit(event) {
		if (!isYandexSelected()) return;

		const pointId = document.getElementById('yandex_delivery_pickup_point_id')?.value || '';
		if (pointId) return;

		event.preventDefault();
		event.stopImmediatePropagation();
		setStatus(config.labels.required);

		if (window.toast && typeof window.toast.error === 'function') {
			window.toast.error(config.labels.required);
		}
	}

	function loadYandexMaps() {
		if (window.ymaps) {
			return new Promise(function (resolve) {
				ymaps.ready(resolve);
			});
		}

		if (state.mapPromise) return state.mapPromise;

		state.mapPromise = new Promise(function (resolve, reject) {
			const script = document.createElement('script');
			const params = new URLSearchParams({ lang: 'ru_RU' });
			if (config.mapApiKey) params.set('apikey', config.mapApiKey);
			script.src = `https://api-maps.yandex.ru/2.1/?${params.toString()}`;
			script.async = true;
			script.onload = function () {
				if (!window.ymaps) {
					reject(new Error('Yandex Maps API unavailable'));
					return;
				}
				ymaps.ready(resolve);
			};
			script.onerror = reject;
			document.head.appendChild(script);
		});

		return state.mapPromise;
	}

	function escapeHtml(value) {
		return String(value || '').replace(/[&<>"']/g, function (char) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			}[char];
		});
	}

	function escapeAttr(value) {
		return escapeHtml(value).replace(/`/g, '&#096;');
	}

	function escapeSelector(value) {
		if (window.CSS && typeof window.CSS.escape === 'function') {
			return window.CSS.escape(value);
		}

		return String(value).replace(/["\\]/g, '\\$&');
	}

	$(function () {
		toggleContainer();
		$(document.body).on('updated_checkout', toggleContainer);
		$(document).on('change', `${selectors.shipping} ${selectors.methodInput}`, toggleContainer);
		$(document).on('change change.select2 select2:select', `${selectors.city}, ${selectors.country}`, function () {
			state.points = [];
			state.selectedPoint = null;
			writeSelectedPoint(null);
			storeSelectedPoint(null);
			renderSelectedPoint(null);
			toggleContainer();
		});
		$(document).on('click', '#place_order', validateBeforeSubmit);
	});
})(jQuery, window.nitisvetaYandexDelivery);
