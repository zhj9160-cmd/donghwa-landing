/* ============================================================
 * Firebase Realtime Database 웹슬라이드 동기화 모듈
 * - 서버(PHP) 없이 정적 호스팅(Vercel · GitHub Pages · Netlify)에서 청중 동기화
 * - 데이터 경로: decks/{덱 이름}/state = { slide, locked, pdf, updatedAt }
 *                decks/{덱 이름}/viewers/{접속 키} = 접속 시각 (창을 닫으면 자동 삭제)
 * - 쓰기 권한: database.rules.json 의 admins/{UID} = true 인 계정만
 * ============================================================ */
import { initializeApp } from 'https://www.gstatic.com/firebasejs/12.19.0/firebase-app.js';
import {
  getDatabase, ref, get, set, update, push, onValue, onDisconnect, serverTimestamp
} from 'https://www.gstatic.com/firebasejs/12.19.0/firebase-database.js';
import {
  getAuth, signInWithEmailAndPassword, signOut, onAuthStateChanged
} from 'https://www.gstatic.com/firebasejs/12.19.0/firebase-auth.js';

const DEFAULT_STATE = { slide: 0, locked: true, pdf: true };

/* firebase-config.js 를 아직 채우지 않았으면 false - 이때 덱은 자유 열람으로 동작 */
export function isConfigured(cfg) {
  return !!(cfg && typeof cfg.apiKey === 'string' && cfg.apiKey && !cfg.apiKey.includes('[') && cfg.databaseURL);
}

export function createSync(cfg, deckId) {
  const app = initializeApp(cfg);
  const db = getDatabase(app);
  const auth = getAuth(app);
  const base = 'decks/' + deckId;
  const stateRef = ref(db, base + '/state');

  let isAdmin = false;
  let user = null;
  const adminListeners = [];

  /* 로그인만으로는 부족 - 이메일 가입은 누구나 가능하므로 admins 목록에 있는 UID 인지 확인 */
  onAuthStateChanged(auth, async (u) => {
    user = u; isAdmin = false;
    if (u) {
      try { isAdmin = (await get(ref(db, 'admins/' + u.uid))).val() === true; } catch (e) { isAdmin = false; }
      if (isAdmin) {
        const cur = await get(stateRef);
        if (!cur.exists()) await set(stateRef, { ...DEFAULT_STATE, updatedAt: serverTimestamp() });
      }
    }
    adminListeners.forEach((cb) => cb(isAdmin, user));
  });

  function patch(values) {
    if (!isAdmin) return Promise.reject(new Error('not-admin'));
    return update(stateRef, { ...values, updatedAt: serverTimestamp() });
  }

  return {
    /* 상태가 바뀔 때마다 즉시 호출 (폴링 없음) */
    onState(cb) {
      return onValue(stateRef, (s) => cb({ ...DEFAULT_STATE, ...(s.val() || {}) }), () => cb(null));
    },
    onConnection(cb) {
      return onValue(ref(db, '.info/connected'), (s) => cb(s.val() === true));
    },
    onAdmin(cb) { adminListeners.push(cb); cb(isAdmin, user); },
    login(email, pw) { return signInWithEmailAndPassword(auth, email, pw); },
    logout() { return signOut(auth); },
    setSlide(n) { return patch({ slide: Math.max(0, n | 0) }); },
    setLock(on) { return patch({ locked: !!on }); },
    setPdf(on) { return patch({ pdf: !!on }); },
    reset() { return patch({ ...DEFAULT_STATE }); },

    /* 접속자 수: 창을 닫거나 연결이 끊기면 서버가 자동으로 지움 */
    joinViewers() {
      const me = push(ref(db, base + '/viewers'));
      onValue(ref(db, '.info/connected'), (s) => {
        if (s.val() !== true) return;
        onDisconnect(me).remove().then(() => set(me, serverTimestamp()));
      });
    },
    /* 관리자·프로젝션 화면만 구독 권장 - 모든 청중이 구독하면 접속자² 만큼 전송량 증가 */
    onViewers(cb) {
      return onValue(ref(db, base + '/viewers'), (s) => cb(s.size), () => cb(null));
    }
  };
}
