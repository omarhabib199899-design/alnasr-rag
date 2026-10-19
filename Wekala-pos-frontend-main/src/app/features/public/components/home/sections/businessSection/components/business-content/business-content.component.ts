import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

@Component({
  selector: 'app-business-content',
  standalone: true,
  imports: [],
  templateUrl: './business-content.component.html',
  styleUrl: './business-content.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class BusinessContentComponent {

  // Badge label identifying the purpose of the business section
  readonly badge = signal('Business Types');

  // Primary heading for the section
  readonly title = signal(
    'Built for Different Types of Businesses'
  );

  // Description highlighting the system's adaptability
  readonly description = signal(
    'Wekala POS adapts to how your business operates — from busy kitchens to online storefronts.'
  );

}