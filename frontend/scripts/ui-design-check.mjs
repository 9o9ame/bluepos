import { readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import process from 'node:process'

const root = path.resolve('src')
const failures = []

async function walk(dir) {
  const entries = await readdir(dir, { withFileTypes: true })
  const files = []
  for (const entry of entries) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) files.push(...(await walk(full)))
    else files.push(full)
  }
  return files
}

const files = await walk(root)

for (const file of files) {
  const relative = path.relative(root, file).replaceAll('\\', '/')
  const source = await readFile(file, 'utf8')

  if (file.endsWith('.tsx') && /<select\b/.test(source)) {
    failures.push(`${relative}: native <select> found; use UiSelect/UiMultiSelect or document an approved exception.`)
  }

  if (relative.startsWith('components/ui/') && file.endsWith('.css')) {
    const forbiddenPrimary = /#(?:1769aa|0f7dcc|1f4e79|2b6aa3|0969da|58a6ff|2388c9|3c94cf)\b/i
    if (forbiddenPrimary.test(source)) {
      failures.push(`${relative}: hard-coded primary-theme color found in canonical UI CSS.`)
    }

    const numericLayer = /z-index\s*:\s*(\d+)/g
    for (const match of source.matchAll(numericLayer)) {
      if (Number(match[1]) >= 1000) {
        failures.push(`${relative}: numeric overlay z-index ${match[1]} found; use uiLayers contract.`)
      }
    }
  }
}

if (failures.length > 0) {
  console.error('BluePOS UI design-system check failed:')
  for (const failure of failures) console.error(`- ${failure}`)
  process.exit(1)
}

console.log('BluePOS UI design-system check passed.')
