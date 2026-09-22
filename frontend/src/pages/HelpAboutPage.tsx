import { APP_NAME, APP_VERSION } from '../config/app'
import { DesktopPanel } from '../components/desktop/DesktopPanel'

export function HelpAboutPage() {
  return (
    <DesktopPanel title={`About ${APP_NAME}`}>
      <dl className="kv-grid max-w-xl">
        <dt>Application</dt>
        <dd>{APP_NAME}</dd>
        <dt>Version</dt>
        <dd>v{APP_VERSION}</dd>
        <dt>Workspace</dt>
        <dd>Tenant desktop POS shell</dd>
      </dl>
      <p className="later-banner">Documentation and in-app help topics will be added in a later phase.</p>
    </DesktopPanel>
  )
}
