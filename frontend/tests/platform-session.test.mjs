import assert from 'node:assert/strict'
import test from 'node:test'
import { QueryClient, QueryObserver } from '@tanstack/react-query'
import { authenticatePlatformSession, clearPlatformSession } from '../src/features/platform/authenticatePlatformSession.ts'

test('sign out stays on login until a subsequent visit checks the remembered session', async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const user = { ulid: 'remembered-admin' }
  let sessionChecks = 0
  const options = {
    queryKey: ['platform', 'me'],
    queryFn: async () => { sessionChecks++; return user },
  }
  const observer = new QueryObserver(client, options)
  const unsubscribe = observer.subscribe(() => {})
  await observer.refetch()
  client.setQueryData(['platform', 'dashboard'], { confidential: true })
  const checksBeforeLogout = sessionChecks

  await client.cancelQueries({ queryKey: options.queryKey, exact: true })
  clearPlatformSession(client)
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(observer.getCurrentResult().data, null)
  assert.equal(sessionChecks, checksBeforeLogout)
  assert.equal(client.getQueryData(['platform', 'dashboard']), undefined)

  unsubscribe()
  const nextVisit = new QueryObserver(client, options)
  const unsubscribeNext = nextVisit.subscribe(() => {})
  await nextVisit.refetch()
  assert.equal(nextVisit.getCurrentResult().data, user)
  assert.ok(sessionChecks > checksBeforeLogout)
  unsubscribeNext()
  client.clear()
})

test('an old unauthorized session response cannot overwrite a verified MFA login', async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const key = ['platform', 'me']
  let rejectOldRequest
  const oldRequest = client.fetchQuery({
    queryKey: key,
    queryFn: () => new Promise((_, reject) => { rejectOldRequest = reject }),
  }).catch(() => undefined)
  const user = { ulid: 'verified-admin', must_change_password: false }

  const result = await authenticatePlatformSession(client, async () => {
    assert.equal(client.getQueryState(key).fetchStatus, 'idle')
    return user
  }, async () => user)
  rejectOldRequest(new Error('401 from before MFA verification'))
  await oldRequest
  await new Promise((resolve) => setImmediate(resolve))

  assert.equal(result, user)
  assert.equal(client.getQueryData(key), user)
  assert.equal(client.getQueryState(key).error, null)
  client.clear()
})

test('a rejected MFA code never checks or establishes an authenticated session', async () => {
  const client = new QueryClient()
  let checkedSession = false
  await assert.rejects(authenticatePlatformSession(client, async () => {
    throw new Error('Invalid verification code')
  }, async () => { checkedSession = true }), /Invalid verification code/)
  assert.equal(checkedSession, false)
  assert.equal(client.getQueryData(['platform', 'me']), undefined)
  client.clear()
})

test('a successful MFA response without a working session does not allow navigation', async () => {
  const client = new QueryClient()
  await assert.rejects(authenticatePlatformSession(client,
    async () => ({ ulid: 'verified-admin' }),
    async () => { throw new Error('Session cookie was not accepted') },
  ), /Session cookie was not accepted/)
  assert.equal(client.getQueryData(['platform', 'me']), undefined)
  client.clear()
})
