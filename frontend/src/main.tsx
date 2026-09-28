import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.tsx'
import '@fontsource/inter/400.css'
import '@fontsource/inter/500.css'
import '@fontsource/inter/600.css'
import '@fontsource/inter/700.css'
import '@fontsource/roboto-condensed/400.css'
import '@fontsource/roboto-condensed/500.css'
import '@fontsource/roboto-condensed/600.css'
import '@fontsource/roboto-condensed/700.css'
import { AppearanceProvider } from './features/appearance/AppearanceProvider'
import { applyAppDisplayMode } from './features/desktop/appDisplayMode'
import './index.css'
import './appearance.css'

applyAppDisplayMode()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppearanceProvider>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </AppearanceProvider>
  </StrictMode>,
)
