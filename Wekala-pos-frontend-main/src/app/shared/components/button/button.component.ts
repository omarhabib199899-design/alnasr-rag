import { ChangeDetectionStrategy, Component, computed, input} from '@angular/core';
import { NgClass, NgTemplateOutlet } from '@angular/common';  
/**
 * Defines the visual style of the button.
 */
export type ButtonVariant = | 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger' | 'success' | 'warning';

/**
 * Defines the height and padding of the button.
 */
export type ButtonSize = | 'xs' | 'sm' | 'md' | 'lg' | 'xl';

/**
 * Defines the border-radius applied to the button.
 */
export type ButtonRadius = | 'sm' | 'md' | 'lg' | 'full';
export type ButtonType = 'button' | 'submit' | 'reset';

@Component({
  selector: 'app-button',
  standalone: true,
  imports: [NgClass, NgTemplateOutlet],
  templateUrl: './button.component.html',
  styleUrl: './button.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ButtonComponent {

  /* ==========================================================
      APPEARANCE
  ========================================================== */

  // Specifies the style variant of the button
  readonly variant = input<ButtonVariant>('primary');
  
  // Specifies the size of the button
  readonly size = input<ButtonSize>('md');

  // Specifies the border radius style of the button
  readonly radius = input<ButtonRadius>('md');

  /* ==========================================================
      BEHAVIOR
  ========================================================== */

  // Sets the native HTML button type attribute
  readonly type = input<ButtonType>('button');
  // Custom classes to be applied to the button
  readonly className = input('');
  // Disables the button interaction
  readonly disabled = input(false);
  // Navigation link if the button acts as a redirector
  readonly link = input('');
  // Indicates if the button is in a loading state
  readonly loading = input(false);

  // Expands the button width to 100% of the container
  readonly fullWidth = input(false);

  // Applies elevation styles
  readonly elevated = input(false);

  // Applies a fully rounded style to the button
  readonly rounded = input(false);

  // Indicates if the button only contains an icon
  readonly iconOnly = input(false);

  /* ==========================================================
      CONTENT
  ========================================================== */

  // Optional icon displayed to the left of the content
  readonly leftIcon = input<string>();

  // Optional icon displayed to the right of the content
  readonly rightIcon = input<string>();

  // Aria label for accessibility purposes
  readonly ariaLabel = input('');

  /* ==========================================================
      COMPUTED
  ========================================================== */

  // Determines if the button should be disabled based on state
  readonly isDisabled = computed(() => {

    return this.disabled() || this.loading();

  });

// Generates the CSS class object for the component based on input states
readonly classes = computed(() => {

  const classes: Record<string, boolean> = {

    'app-btn': true,

    [`app-btn--${this.variant()}`]: true,

    [`app-btn--${this.size()}`]: true,

    [`app-btn--radius-${this.radius()}`]: true,

    'app-btn--block': this.fullWidth(),

    'app-btn--loading': this.loading(),

    'app-btn--rounded': this.rounded(),

    'app-btn--elevated': this.elevated(),

    'app-btn--icon': this.iconOnly()

  };

  // Parses and includes custom user-provided classes
  if (this.className()) {
    this.className()
      .split(' ')
      .filter(Boolean)
      .forEach(c => classes[c] = true);
  }

  return classes;

});

}