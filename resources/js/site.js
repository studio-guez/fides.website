import Alpine from 'alpinejs';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
	Alpine.data('historyCarousel', () => ({
		active: 0,
		displayIndex: 0,
		animationMs: 700,
		staggerMs: 80,
		items: [],
		years: [],
		phase: 'idle',
		direction: 1,
		timer: null,
		display: {
			point_1: { current: '', incoming: '' },
			point_2: { current: '', incoming: '' },
			point_3: { current: '', incoming: '' },
		},
		init() {
			const slides = Array.from(this.$el.querySelectorAll('[data-history-slide]'));
			this.items = slides.map((slide) => ({
				year: slide.dataset.year || '',
				point_1: slide.querySelector('[data-history-point="point_1"]')?.innerHTML.trim() || '',
				point_2: slide.querySelector('[data-history-point="point_2"]')?.innerHTML.trim() || '',
				point_3: slide.querySelector('[data-history-point="point_3"]')?.innerHTML.trim() || '',
			}));
			this.years = this.items.map((item) => item.year);

			if (!this.items.length) {
				return;
			}

			this.syncDisplay(this.items[0]);
			this.displayIndex = 0;
		},
		syncDisplay(item) {
			['point_1', 'point_2', 'point_3'].forEach((key) => {
				this.display[key].current = item[key] || '';
				this.display[key].incoming = item[key] || '';
			});
		},
		setIncoming(item) {
			['point_1', 'point_2', 'point_3'].forEach((key) => {
				this.display[key].incoming = item[key] || '';
			});
		},
		panelTop(key) {
			return this.direction === 1 ? this.display[key].current : this.display[key].incoming;
		},
		panelBottom(key) {
			return this.direction === 1 ? this.display[key].incoming : this.display[key].current;
		},
		panelOffset(key) {
			return Number(key.split('_')[1]) - 1;
		},
		panelColorClass(key, itemIndex = this.displayIndex) {
			const tones = [
				'bg-blue text-white',
				'bg-gray text-white',
				'bg-blue-light',
			];

			return tones[(itemIndex + this.panelOffset(key)) % tones.length];
		},
        panelShapeClass(key, itemIndex = this.displayIndex) {
            const shapes = [
                'history-1',
                'history-2',
                'history-3',
                'history-5',
                'history-4',
            ];

            return shapes[(itemIndex + this.panelOffset(key)) % shapes.length];
        },
		panelTopClass(key) {
			return this.panelColorClass(key, this.direction === 1 ? this.displayIndex : this.active) + ' ' + this.panelShapeClass(key, this.direction === 1 ? this.displayIndex : this.active);
		},
		panelBottomClass(key) {
			return this.panelColorClass(key, this.direction === 1 ? this.active : this.displayIndex) + ' ' + this.panelShapeClass(key, this.direction === 1 ? this.active : this.displayIndex);
		},
		panelTrackClass() {
			if (this.phase === 'prepare') {
				return this.direction === 1 ? 'translate-y-0' : '-translate-y-1/2';
			}

			if (this.phase === 'move') {
				return this.direction === 1 ? '-translate-y-1/2' : 'translate-y-0';
			}

			return 'translate-y-0';
		},
		panelDelayStyle(key) {
			return `transition-delay: ${this.panelOffset(key) * this.staggerMs}ms;`;
		},
		totalAnimationMs() {
			return this.animationMs + (this.staggerMs * 2);
		},
		go(next, direction = null) {
			if (this.phase !== 'idle' || next === this.active || next < 0 || next >= this.items.length) {
				return;
			}

			this.direction = direction ?? (next > this.active ? 1 : -1);
			const nextItem = this.items[next];
			this.phase = 'prepare';
			this.setIncoming(nextItem);
			this.active = next;

			requestAnimationFrame(() => {
				requestAnimationFrame(() => {
					this.phase = 'move';
				});
			});

			window.clearTimeout(this.timer);
			this.timer = window.setTimeout(() => {
				this.syncDisplay(nextItem);
				this.displayIndex = next;
				this.phase = 'idle';
			}, this.totalAnimationMs());
		},
		next() {
			this.go(this.active === this.items.length - 1 ? 0 : this.active + 1, 1);
		},
		prev() {
			this.go(this.active === 0 ? this.items.length - 1 : this.active - 1, -1);
		},
	}));
});
Alpine.start();
