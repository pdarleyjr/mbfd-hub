# Retained vendor views

The bounded release review compared all 31 existing overrides with the exact upstream
commits pinned by `composer.lock`: Filament **v3.3.54** and Laravel Pulse **v1.7.0**.
`pulse/dashboard.blade.php` was removed because its normalized source matched upstream.
The 30 remaining views retain concrete accessibility, contrast, or local-asset repairs.
Supported panel configuration handles brand colors and navigation; it does not expose the
markup changes below. Preserve these repairs until the corresponding upstream templates
provide the same behavior, then remove the redundant copy during a dependency update.

Paths below are relative to `resources/views/vendor/`. Abbreviated paths in a row use the
package prefix from its first view.

| Views | Reason retained |
|---|---|
| `filament-forms/components/date-time-picker.blade.php` | Keyboard-focusable trigger, no input nested inside a button, named field/time/month/calendar controls, and valid listbox semantics. |
| `filament-forms/components/toggle.blade.php` | Accessible switch name. |
| `filament-forms/components/rich-editor.blade.php` | Named editor and keyboard-accessible scrolling toolbar. Content sanitization is already provided by the pinned upstream release. |
| `filament-forms/components/select.blade.php` | Removes invalid `aria-selected` from selected-chip markup. |
| `filament-forms/components/wizard.blade.php` | Removes invalid `role="step"` and makes overflowing step navigation keyboard accessible. |
| `filament-forms/components/repeater/index.blade.php`; `filament/components/grid/index.blade.php` | Valid list/grid nesting using the appropriate `ul`/`li` elements. |
| `filament-infolists/component-container.blade.php`; `components/entry-wrapper/index.blade.php`; `components/entry-wrapper/label.blade.php`; `components/repeatable-entry.blade.php` | Valid grouped description lists and list/listitem nesting, including hidden field labels. |
| `filament-panels/components/sidebar/group.blade.php`; `components/sidebar/item.blade.php`; `components/page/sub-navigation/select.blade.php` | Names for collapsed and icon-only navigation controls. |
| `filament-panels/components/user-menu.blade.php`; `components/avatar/user.blade.php` | Named account trigger, visible identity, and named local initials fallback while preserving custom avatars; avoids the default third-party avatar dependency. |
| `filament-tables/columns/icon-column.blade.php`; `columns/text-column.blade.php`; `columns/toggle-column.blade.php`; `components/filters/indicators.blade.php` | Readable icon/Yes–No state, fallback names for empty linked cells, named switches, and a named remove-all-filters control. |
| `filament/components/section/index.blade.php`; `components/tabs/item.blade.php` | Name and expanded state on the actual section toggle; valid string `true`/`false` tab selection states. |
| `pulse/components/card-header.blade.php`; `components/http-method-badge.blade.php`; `components/no-results.blade.php`; `components/user-card.blade.php` | Legible contrast. |
| `pulse/livewire/period-selector.blade.php` | Legible contrast and at least 24px control targets. |
| `pulse/components/theme-switcher.blade.php`; `components/scroll.blade.php` | Named theme trigger with expanded state and keyboard-focusable scrolling. |
| `pulse/livewire/slow-outgoing-requests.blade.php` | Local decorative host initial instead of an external avatar lookup. |

This review retains the completed repairs and does not change Filament, Livewire, or Pulse
business behavior or authorize a wider vendor-template refactor.
