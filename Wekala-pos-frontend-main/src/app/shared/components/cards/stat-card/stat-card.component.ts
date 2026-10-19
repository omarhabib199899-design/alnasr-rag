import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input
} from '@angular/core';
import { NgClass } from '@angular/common';

import { CountUpComponent } from '../../count-up/count-up.component';

@Component({
  selector: 'app-stat-card',
  standalone: true,
  imports: [
    NgClass,
    CountUpComponent
  ],
  templateUrl: './stat-card.component.html',
  styleUrl: './stat-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class StatCardComponent {

  /* ===========================================
     CONTENT
  =========================================== */

  // The main label for the statistic
  readonly title = input.required<string>();

  // The numeric value or text to display
  readonly value = input.required<string | number>();

  // Optional secondary status label
  readonly badge = input<string>();

  // Optional icon identifier
  readonly icon = input<string>();

  /* ===========================================
     COUNT UP
  =========================================== */

  // Text to prepend to the count up value
  readonly prefix = input('');

  // Text to append to the count up value
  readonly suffix = input('');

  // Animation duration in milliseconds
  readonly duration = input(1400);

  // Number of decimal places to show
  readonly decimals = input(0);

  // Toggle for numeric group separator (e.g., commas)
  readonly separator = input(true);

  /* ===========================================
     STYLE
  =========================================== */

  // Additional custom CSS classes
  readonly className = input('');

  /* ===========================================
     COMPUTED
  =========================================== */

  // Checks if the provided value is a number to enable animation
  readonly isNumber = computed(
    () => typeof this.value() === 'number'
  );

  // Aggregates component base class with user-provided custom classes
  readonly classes = computed(() => [

    'stat-card',

    this.className()

  ]);

}