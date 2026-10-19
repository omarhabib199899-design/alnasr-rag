import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input
} from '@angular/core';

import { NgClass, NgTemplateOutlet } from '@angular/common';

/**
 * Defines the available visual variants for the info card.
 */
export type InfoCardVariant =
  | 'default'
  | 'primary'
  | 'success'
  | 'warning';

/**
 * Defines the available size presets for the info card.
 */
export type InfoCardSize =
  | 'sm'
  | 'md'
  | 'lg';

@Component({
  selector: 'app-info-card',
  standalone: true,
  imports: [
    NgClass,
    NgTemplateOutlet
  ],
  templateUrl: './info-card.component.html',
  styleUrl: './info-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class InfoCardComponent {

  /* ===========================================
     CONTENT
  =========================================== */

  // Optional badge text to display on the card
  readonly badge = input('');
  // Optional navigation link for the card
  readonly link = input('');

  // The main title displayed on the card
  readonly title = input.required<string>();

  // Supporting description text for the card
  readonly description = input('');

  // Icon identifier to be rendered within the card
  readonly icon = input('');

  // Optional leading element or text content
  readonly leading = input('');

  // Toggles the visibility of a directional arrow
  readonly arrow = input(false);

  /* ===========================================
     STYLE
  =========================================== */

  // Determines the visual theme of the card
  readonly variant = input<InfoCardVariant>('default');

  // Determines the overall scale/size of the card
  readonly size = input<InfoCardSize>('md');

  // Additional custom CSS classes to apply to the card
  readonly className = input('');

  /* ===========================================
     COMPUTED
  =========================================== */

  // Aggregates base classes and dynamic modifiers for the component
  readonly classes = computed(() => [

    'info-card',

    `info-card--${this.variant()}`,

    `info-card--${this.size()}`,

    this.className()

  ]);

}