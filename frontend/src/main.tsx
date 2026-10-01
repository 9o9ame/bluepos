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
import { FeedbackProvider } from './feedback/FeedbackProvider'
import { applyAppDisplayMode } from './features/desktop/appDisplayMode'
import './density.css'
import './index.css'
import './appearance.css'
import './density-shell.css'

applyAppDisplayMode()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppearanceProvider>
      <FeedbackProvider>
        <BrowserRouter>
          <App />
        </BrowserRouter>
      </FeedbackProvider>
    </AppearanceProvider>
  </StrictMode>,
)
