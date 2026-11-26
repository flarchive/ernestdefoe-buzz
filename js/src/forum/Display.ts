import app from 'flarum/forum/app';
import stringToColor from 'flarum/common/utils/stringToColor';
import extractText from 'flarum/common/utils/extractText';
import type { BuzzConfig, BuzzEvent, BuzzUser } from '../common/types';

/*
 * Draws Buzz events: one at a time, each for a minimum time, bursts of the
 * same kind collapsed into "+N more", paused while hovered or focused.
 *
 * 🚨 Everything a member wrote (titles, names) goes in as TEXT
 * (textContent), never as HTML. Links come only from app.route() or from a
 * path on this forum.
 */

const MARK = '\u0001';
const SUBJECT = '\u0002';

/** Text with its subject (a title, a rank…) in bold: the server marks it with SUBJECT. */
function withSubject<T extends HTMLElement>(node: T, text: string): T {
  String(text)
    .split(SUBJECT)
    .forEach((part, i) => part && node.appendChild(i % 2 ? el('strong', 'Buzz-subject', part) : document.createTextNode(part)));
  return node;
}
const MAX_QUEUE = 20;
/** Classes Flarum toggles on `.App` while the page scrolls. */
const SCROLL_CLASSES = ['affix', 'scrolled'];
const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// Things fixed to the bottom of the screen a toast must sit above.
// Cookie banners too: ernestdefoe/advanced-cookie-consent's, and the common
// third-party ones (Cookie Consent, OneTrust, Cookiebot, generic ids), and
// Quickdraw's floating button on discussion pages.
const OBSTACLES = [
  '.AlertManager .Alert, .Composer, .MobileTab-bar, .pl-peek, .pl-dock > *, #bespoke-totop, .Bespoke-toTop, #bespoke-launcher',
  '.Quickdraw:not(.is-hidden) .Quickdraw-fab',
  '.CookieBanner, .cc-window, .cc-banner, #onetrust-banner-sdk, #CybotCookiebotDialog, #cookie-banner, .cookie-banner',
].join(', ');

function el<K extends keyof HTMLElementTagNameMap>(tag: K, className?: string, text?: string): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function trans(key: string, params: Record<string, unknown> = {}): string {
  return extractText(app.translator.trans('ernestdefoe-buzz.forum.' + key, params));
}

function hrefFor(event: BuzzEvent): string | null {
  const route = event.route;
  if (route && route.name in app.routes) {
    try {
      return app.route(route.name, { ...route.params });
    } catch (e) {
      // A route whose parameters no longer fit: no link.
    }
  }
  if (typeof event.url === 'string' && /^\/(?!\/)/.test(event.url)) return app.forum.attribute('basePath') + event.url;
  return null;
}

function profileHref(user: BuzzUser | null): string | null {
  return user && app.routes.user ? app.route('user', { username: user.slug }) : null;
}

/** A plain left click on one of our links routes inside the app. */
function navigate(event: Event, href: string | null): void {
  const e = event as MouseEvent;
  if (!href || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  e.preventDefault();
  m.route.set(href);
}

/** Makes a node open href inside the app; no href, no link. */
function linkTo<T extends HTMLElement>(node: T, href: string | null): T {
  if (href) {
    (node as unknown as HTMLAnchorElement).href = href;
    node.addEventListener('click', (e) => navigate(e, href));
  }
  return node;
}

/** A link to the member's profile around their face. */
function profileLink(className: string, user: BuzzUser, href: string, child: Node): HTMLAnchorElement {
  const link = linkTo(el('a', className), href);
  link.setAttribute('aria-label', user.name);
  link.appendChild(child);
  return link;
}

function icon(className: string): HTMLElement {
  const node = el('i', className);
  node.setAttribute('aria-hidden', 'true');
  return node;
}

function closeButton(onclick: () => void): HTMLButtonElement {
  const close = el('button', 'Buzz-close');
  close.type = 'button';
  close.setAttribute('aria-label', trans('close'));
  close.appendChild(el('i', 'fas fa-times'));
  close.addEventListener('click', onclick);
  return close;
}

/** The member's picture, or their initial on a colour of their name. */
function avatarOf(user: BuzzUser): HTMLElement {
  if (!user.avatar) {
    const initial = el('span', 'Buzz-avatar', (user.name || '?').charAt(0).toUpperCase());
    initial.style.setProperty('--buzz-avatar', '#' + stringToColor(user.name || ''));
    return initial;
  }
  const img = el('img', 'Buzz-avatar');
  img.src = user.avatar;
  img.alt = '';
  img.loading = 'lazy';
  return img;
}

/** A toast on screen, with the time it has left (kept while paused). */
interface ToastItem {
  node: HTMLElement;
  timer?: number;
  remaining: number;
  startedAt: number;
}

export default class Display {
  cfg: BuzzConfig;
  root: HTMLDivElement;
  queue: BuzzEvent[] = [];
  /** Toasts on screen, oldest first. */
  active: ToastItem[] = [];
  /** The ticker's event on screen. */
  current: BuzzEvent | null = null;
  paused = false;
  marquee: boolean;
  timer = 0;
  fillTimer = 0;
  /** Watches the page for bars coming and going while something is on screen. */
  observer: MutationObserver | null = null;
  placing = 0;
  remaining = 0;
  startedAt = 0;

  constructor(cfg: BuzzConfig) {
    this.cfg = cfg;
    this.marquee = cfg.style === 'ticker' && cfg.tickerMode === 'marquee' && !reducedMotion();

    const root = (this.root = el('div', `Buzz Buzz--${cfg.style}` + (cfg.style === 'toast' ? ` Buzz--${cfg.side}` : '')));
    root.setAttribute('role', 'status');
    root.setAttribute('aria-live', 'polite');
    root.setAttribute('aria-label', trans('label'));
    root.hidden = true;
    root.style.setProperty('--buzz-x', cfg.offsetX + 'px');
    root.style.setProperty('--buzz-y', cfg.offsetY + 'px');

    root.addEventListener('mouseenter', () => this.pause(true));
    root.addEventListener('mouseleave', () => this.pause(false));
    root.addEventListener('focusin', () => this.pause(true));
    root.addEventListener('focusout', () => this.pause(false));

    document.body.appendChild(root);

    this.place = this.place.bind(this);
    this.settled = this.settled.bind(this);
    window.addEventListener('resize', this.place, { passive: true });
    if (cfg.style === 'ticker') window.addEventListener('scroll', this.place, { passive: true });
  }

  /** Events arrive oldest first. */
  push(events: BuzzEvent[]): void {
    if (!this.allowedHere()) return;
    this.queue.push(...events);
    if (this.queue.length > MAX_QUEUE) this.queue.splice(0, this.queue.length - MAX_QUEUE);
    if (this.cfg.style === 'toast') this.fill();
    else if (!this.current) this.next();
  }

  // ── Bottom toast: a stack of small cards, as Invision's Live Activity ─────

  /** Cards on screen at once: the admin's number on a computer, one on a phone. */
  maxToasts(): number {
    return window.innerWidth < 768 ? 1 : Math.max(1, this.cfg.maxToasts || 2);
  }

  /** Bring on queued events while there is room, oldest first; new cards slide up at the bottom. */
  fill(): void {
    clearTimeout(this.fillTimer);
    if (!this.allowedHere()) {
      this.queue = [];
      return;
    }
    // Writing a post: wait, and look again in a moment.
    if (this.held()) {
      this.fillTimer = window.setTimeout(() => this.fill(), 2000);
      return;
    }
    while (this.active.length < this.maxToasts() && this.queue.length) this.showToast(this.queue.shift()!);
  }

  showToast(event: BuzzEvent): void {
    const item: ToastItem = { node: this.toast(event), remaining: 0, startedAt: 0 };
    this.active.push(item);
    this.root.appendChild(item.node);
    this.root.hidden = false;
    this.place();
    this.watch();
    // Next frame, so the entrance transition runs.
    requestAnimationFrame(() => item.node.classList.add('is-in'));
    this.time(item, this.cfg.seconds * 1000);
  }

  time(item: ToastItem, ms: number): void {
    item.remaining = ms;
    item.startedAt = Date.now();
    if (!this.paused) item.timer = window.setTimeout(() => this.dismiss(item), ms);
  }

  dismiss(item: ToastItem): void {
    clearTimeout(item.timer);
    const i = this.active.indexOf(item);
    if (i === -1) return;
    this.active.splice(i, 1);
    item.node.classList.remove('is-in');
    window.setTimeout(
      () => {
        item.node.remove();
        if (!this.active.length && !this.queue.length) this.hide();
        this.place();
      },
      reducedMotion() ? 0 : 250
    );
    this.fill();
  }

  /** One card: avatar (with the event's icon), the name on one line, what happened on the next. */
  toast(event: BuzzEvent): HTMLElement {
    const cfg = this.cfg;
    const profile = cfg.profileLink && cfg.username ? profileHref(event.user) : null;
    const card = el('div', 'Buzz-toast');

    const face = el('span', 'Buzz-face');
    if (cfg.avatar && event.user) face.append(avatarOf(event.user), icon('Buzz-badge ' + event.icon));
    else face.appendChild(icon('Buzz-avatar Buzz-avatar--icon ' + event.icon));
    card.appendChild(profile && event.user ? profileLink('Buzz-faceLink', event.user, profile, face) : face);

    card.appendChild(this.toastBody(event, profile));
    card.appendChild(
      closeButton(() => {
        const item = this.active.find((a) => a.node === card);
        if (item) this.dismiss(item);
      })
    );

    return card;
  }

  /** The name (when the text names someone) over what happened, its subject in bold. */
  toastBody(event: BuzzEvent, profile: string | null): HTMLElement {
    const body = el('div', 'Buzz-body');
    const text = String(event.text);
    const named = text.includes(MARK);

    if (named) {
      const name = this.cfg.username && event.user ? event.user.name : trans('someone');
      body.appendChild(linkTo(el(profile ? 'a' : 'strong', 'Buzz-name', name), profile));
    }
    const href = hrefFor(event);
    const what = named ? text.split(MARK).join('').trim() : text;
    body.appendChild(linkTo(withSubject(el(href ? 'a' : 'span', 'Buzz-action'), what), href));

    return body;
  }

  allowedHere(): boolean {
    return this.cfg.pages !== 'home' || app.current.get('routeName') === 'index';
  }

  held(): boolean {
    const composer = app.composer;
    return this.cfg.hideComposing && !!composer && composer.isVisible() && composer.position !== 'minimized';
  }

  next(): void {
    clearTimeout(this.timer);

    if (!this.queue.length || !this.allowedHere()) {
      this.queue = [];
      this.hide();
      return;
    }

    // Writing a post: wait, and look again in a moment.
    if (this.held()) {
      this.hide();
      this.timer = window.setTimeout(() => this.next(), 2000);
      return;
    }

    if (this.marquee) {
      this.scroll();
      return;
    }

    const event = this.queue.shift()!;
    // A burst of one kind becomes one line and "+N more".
    const same = this.queue.filter((e) => e.type === event.type);
    this.queue = this.queue.filter((e) => e.type !== event.type);

    this.current = event;
    this.render([this.card(event, same.length)]);
    this.wait(this.cfg.seconds * 1000);
  }

  wait(ms: number): void {
    this.remaining = ms;
    this.startedAt = Date.now();
    if (!this.paused) this.timer = window.setTimeout(() => this.advance(), ms);
  }

  advance(): void {
    this.current = null;
    if (this.queue.length) {
      this.root.classList.remove('is-in');
      this.timer = window.setTimeout(() => this.next(), reducedMotion() ? 0 : 250);
    } else {
      this.hide();
    }
  }

  pause(paused: boolean): void {
    if (this.cfg.style === 'toast') {
      if (this.paused === paused || !this.active.length) return;
      this.paused = paused;
      this.active.forEach((item) => {
        if (paused) {
          clearTimeout(item.timer);
          item.remaining = Math.max(1500, item.remaining - (Date.now() - item.startedAt));
        } else {
          this.time(item, item.remaining);
        }
      });
      return;
    }
    if (this.paused === paused || !this.current) return;
    this.paused = paused;
    this.root.classList.toggle('is-paused', paused);
    if (this.marquee) return;
    if (paused) {
      clearTimeout(this.timer);
      this.remaining = Math.max(1500, this.remaining - (Date.now() - this.startedAt));
    } else {
      this.wait(this.remaining);
    }
  }

  close(): void {
    clearTimeout(this.timer);
    this.active.forEach((item) => clearTimeout(item.timer));
    this.active = [];
    this.queue = [];
    this.current = null;
    this.paused = false;
    this.hide();
  }

  /** Marquee: everything queued scrolls past once, at an even reading speed. */
  scroll(): void {
    const events = this.queue.splice(0);
    this.current = events[events.length - 1];
    const track = el('div', 'Buzz-track');
    events.forEach((event) => track.appendChild(this.card(event, 0)));
    this.render([track]);

    const distance = this.root.clientWidth + track.scrollWidth;
    track.style.setProperty('--buzz-from', this.root.clientWidth + 'px');
    track.style.setProperty('--buzz-to', -track.scrollWidth + 'px');
    track.style.animationDuration = Math.max(8, distance / 70) + 's';
    // Only now: started before its duration was set, it would end at once.
    track.classList.add('is-running');
    track.addEventListener('animationend', () => {
      this.current = null;
      this.next();
    });
  }

  /** One ticker line: avatar, the event's icon, the text, and "+N more" for a burst. */
  card(event: BuzzEvent, more: number): HTMLElement {
    const href = hrefFor(event);
    const profile = this.cfg.profileLink ? profileHref(event.user) : null;
    const card = el('div', 'Buzz-card');

    if (this.cfg.avatar && event.user) {
      const avatar = avatarOf(event.user);
      // The avatar opens the profile when the text opens the item.
      card.appendChild(profile ? profileLink('Buzz-avatarLink', event.user, profile, avatar) : avatar);
    }
    card.appendChild(icon('Buzz-icon ' + event.icon));
    card.appendChild(this.cardText(event, href, profile));
    if (more > 0) card.appendChild(el('span', 'Buzz-more', trans('more', { count: more })));

    return card;
  }

  /** The text, split where the name goes; the name may be its own link. */
  cardText(event: BuzzEvent, href: string | null, profile: string | null): HTMLElement {
    const body = linkTo(el(href ? 'a' : 'span', 'Buzz-text'), href);
    const parts = String(event.text).split(MARK);
    const name = this.cfg.username && event.user ? event.user.name : trans('someone');
    // No link inside a link: under a linked text the name is bold, and the avatar opens the profile.
    const nameLinks = !!profile && this.cfg.username && !!event.user && !href;

    parts.forEach((part, i) => {
      if (part) body.appendChild(document.createTextNode(part.split(SUBJECT).join('')));
      if (i < parts.length - 1) body.appendChild(nameLinks ? linkTo(el('a', 'Buzz-user', name), profile) : el('strong', 'Buzz-user', name));
    });

    return body;
  }

  render(children: Node[]): void {
    const root = this.root;
    root.replaceChildren(...children);

    root.appendChild(closeButton(() => this.close()));

    root.hidden = false;
    this.place();
    this.watch();
    // Next frame, so the entrance transition runs.
    requestAnimationFrame(() => root.hidden || root.classList.add('is-in'));
  }

  hide(): void {
    this.unwatch();
    this.root.classList.remove('is-in');
    this.root.hidden = true;
    this.root.replaceChildren();
  }

  /** Something outside Buzz, and not the posts or the scrubber, which change on every scroll and never hold a bar. */
  outside(node: Node | EventTarget | null): boolean {
    const elm = node instanceof Element ? node : node instanceof Node ? node.parentElement : null;
    return !!elm && !this.root.contains(elm) && !elm.closest('.PostStreamScrubber, .PostStream');
  }

  /**
   * While something is on screen, place it again only when the page around it
   * changes: alerts, the composer, cookie banners, chat docks and tab bars come
   * and go (nodes, classes, styles), or slide and fade into place
   * (transitionend, animationend). Nothing is measured while the page is still.
   */
  watch(): void {
    if (this.observer) return;
    this.observer = new MutationObserver((records) => {
      // Only an element's first class record holds its class before the batch;
      // compared with the class now, it covers every flip in between.
      const seen = new Set<Node>();
      const first = records.filter((record) => record.attributeName !== 'class' || (!seen.has(record.target) && !!seen.add(record.target)));
      if (first.some((record) => this.outside(record.target) && !this.scrollOnly(record))) this.place();
    });
    this.observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeOldValue: true,
      attributeFilter: ['class', 'style', 'hidden'],
    });
    document.addEventListener('transitionend', this.settled, true);
    document.addEventListener('animationend', this.settled, true);
  }

  unwatch(): void {
    this.observer?.disconnect();
    this.observer = null;
    document.removeEventListener('transitionend', this.settled, true);
    document.removeEventListener('animationend', this.settled, true);
  }

  /**
   * A class change that moves nothing: one that nets out to no change, or, for
   * the toast, Flarum flipping `affix` and `scrolled` on the page as it scrolls
   * (the header animates when they do; neither moves a bar at the bottom). The
   * ticker sits under the header, so it still follows both.
   */
  scrollOnly(record: MutationRecord): boolean {
    if (record.attributeName !== 'class') return false;
    const before = new Set((record.oldValue || '').split(/\s+/).filter(Boolean));
    const after = new Set(((record.target as Element).getAttribute('class') || '').split(/\s+/).filter(Boolean));
    const changed = [...before, ...after].filter((name) => before.has(name) !== after.has(name));
    // A record is read against the class the element has now, so one that nets
    // out to no change (flipped and back in the same batch) moved nothing.
    return changed.every((name) => this.cfg.style === 'toast' && SCROLL_CLASSES.includes(name));
  }

  settled(e: Event): void {
    if (!this.outside(e.target)) return;
    if (this.cfg.style === 'toast' && (e.target as Element).closest?.('.App-header')) return;
    this.place();
  }

  /**
   * Ticker: just under whatever is fixed to the top of the screen (core's
   * header, Bespoke's, a sub-bar under it), found by probing rather than by
   * name. Toast: above whatever is fixed to the bottom on its side.
   */
  place(): void {
    if (this.root.hidden) return;
    if (this.placing) return;
    this.placing = requestAnimationFrame(() => {
      this.placing = 0;
      if (this.cfg.style === 'ticker') this.root.style.setProperty('--buzz-top', this.topEdge() + 'px');
      else this.root.style.setProperty('--buzz-lift', this.lift() + 'px');
    });
  }

  topEdge(): number {
    let top = 0;
    const x = Math.round(window.innerWidth / 2);
    for (let i = 0; i < 3; i++) {
      const hit = document.elementsFromPoint(x, top + 1).find((node) => !this.root.contains(node));
      let node: Element | null | undefined = hit;
      let fixed: Element | null = null;
      while (node && node !== document.body) {
        const pos = getComputedStyle(node).position;
        if (pos === 'fixed' || pos === 'sticky') fixed = node;
        node = node.parentElement;
      }
      const bottom = fixed ? Math.round(fixed.getBoundingClientRect().bottom) : 0;
      if (bottom <= top || bottom > window.innerHeight / 2) break;
      top = bottom;
    }
    return top;
  }

  lift(): number {
    const box = this.root.getBoundingClientRect();
    let lift = 0;
    document.querySelectorAll(OBSTACLES).forEach((node) => {
      const r = node.getBoundingClientRect();
      if (!r.width || !r.height || r.top < window.innerHeight / 2 || getComputedStyle(node).visibility === 'hidden') return;
      if (r.right <= box.left || r.left >= box.right) return;
      lift = Math.max(lift, window.innerHeight - r.top);
    });
    return lift;
  }
}
