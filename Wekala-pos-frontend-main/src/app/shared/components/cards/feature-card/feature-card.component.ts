import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input
} from '@angular/core';

import { NgClass } from '@angular/common';

/**
 * Defines the available visual variants for the feature card.
 */
export type FeatureCardVariant =
  | 'default'
  | 'primary'
  | 'step';

/**
 * Defines the available size presets for the feature card.
 */
export type FeatureCardSize =
  | 'sm'
  | 'md'
  | 'lg';

@Component({
  selector: 'app-feature-card',
  standalone: true,
  imports: [
    NgClass
  ],
  templateUrl: './feature-card.component.html',
  styleUrl: './feature-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class FeatureCardComponent {

  /* ===========================================
     CONTENT
  =========================================== */

  // The main title displayed on the card
  readonly title = input.required<string>();

  // Supporting text description for the card
  readonly description = input('');

  // Icon identifier to be rendered within the card
  readonly icon = input('');

  // Optional leading element or text content
  readonly leading = input('');

  /* ===========================================
     STEP MODE
  =========================================== */

  // Represents the step number when using the step variant
  readonly step = input('');

  // Toggles the visibility of a directional arrow
  readonly arrow = input(false);

  /* ===========================================
     STYLE
  =========================================== */

  // Additional custom CSS classes to apply to the card
  readonly className = input('');

  // Determines the visual theme of the card
  readonly variant = input<FeatureCardVariant>('default');

  // Determines the overall scale/size of the card
  readonly size = input<FeatureCardSize>('md');

  /* ===========================================
     COMPUTED
  =========================================== */

  // Aggregates base classes and dynamic modifiers for the component
  readonly classes = computed(() => [

    'feature-card',

    `feature-card--${this.variant()}`,

    `feature-card--${this.size()}`,

    this.className()

  ]);

}