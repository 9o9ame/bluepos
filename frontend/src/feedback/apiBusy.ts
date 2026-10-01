type BusyKind = 'block' | 'fetch'

type BusyListener = (state: { block: number; fetch: number }) => void

const state = { block: 0, fetch: 0 }
const listeners = new Set<BusyListener>()

function emit() {
  const snapshot = { ...state }
  listeners.forEach((listener) => listener(snapshot))
}

export const apiBusy = {
  begin(kind: BusyKind = 'block') {
    state[kind] += 1
    emit()
  },
  end(kind: BusyKind = 'block') {
    state[kind] = Math.max(0, state[kind] - 1)
    emit()
  },
  subscribe(listener: BusyListener) {
    listeners.add(listener)
    listener({ ...state })
    return () => {
      listeners.delete(listener)
    }
  },
  getState() {
    return { ...state }
  },
}
